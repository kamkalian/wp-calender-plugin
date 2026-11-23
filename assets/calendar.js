(function () {
	'use strict';

	var MONTHS = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
	var WDAYS = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
	var WDAYS_LONG = ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];
	var MAX_PILLS = 3;

	function el(tag, cls, text) {
		var e = document.createElement(tag);
		if (cls) { e.className = cls; }
		if (text !== undefined && text !== null) { e.textContent = text; }
		return e;
	}
	function pad(n) { return (n < 10 ? '0' : '') + n; }
	function iso(y, m, d) { return y + '-' + pad(m + 1) + '-' + pad(d); }
	function parseIso(s) {
		var p = s.split('-');
		return { y: +p[0], m: +p[1] - 1, d: +p[2] };
	}
	// Wochentag Mo=0 … So=6 (UTC-Rechnung, unabhängig von Sommerzeit).
	function weekday(y, m, d) { return (new Date(Date.UTC(y, m, d)).getUTCDay() + 6) % 7; }
	function daysIn(y, m) { return new Date(Date.UTC(y, m + 1, 0)).getUTCDate(); }
	function longDate(s) {
		var p = parseIso(s);
		return WDAYS_LONG[weekday(p.y, p.m, p.d)] + ', ' + p.d + '. ' + MONTHS[p.m] + ' ' + p.y;
	}
	function shortDate(s) {
		var p = parseIso(s);
		return pad(p.d) + '.' + pad(p.m + 1) + '.' + p.y;
	}
	function timeText(ev) {
		if (ev.allDay) { return 'ganztägig'; }
		return ev.startTime + (ev.endTime && ev.endTime !== ev.startTime ? '–' + ev.endTime : '') + ' Uhr';
	}
	function dateRangeText(ev) {
		return ev.startDate === ev.endDate ? longDate(ev.startDate) : longDate(ev.startDate) + ' bis ' + longDate(ev.endDate);
	}

	function Calendar(root) {
		this.root = root;
		this.endpoint = root.getAttribute('data-endpoint');
		this.showSearch = root.getAttribute('data-search') !== '0';
		this.showTags = root.getAttribute('data-tags') !== '0';
		this.tag = root.getAttribute('data-tag') || '';
		this.query = '';
		this.events = [];
		this.tags = [];
		var now = new Date();
		this.year = now.getFullYear();
		this.month = now.getMonth();
		this.todayIso = iso(now.getFullYear(), now.getMonth(), now.getDate());
		this.build();
		this.load();
	}

	Calendar.prototype.build = function () {
		var self = this;
		this.root.textContent = '';
		this.root.classList.add('gcalmk-ready');

		this.tagBar = el('div', 'gcalmk-tags');
		this.tagBar.setAttribute('role', 'group');
		this.tagBar.setAttribute('aria-label', 'Nach Tag filtern');
		if (this.showTags) { this.root.appendChild(this.tagBar); }

		var top = el('div', 'gcalmk-top');
		this.nav = el('div', 'gcalmk-nav');
		var prev = el('button', 'gcalmk-btn', '‹');
		prev.type = 'button';
		prev.setAttribute('aria-label', 'Vorheriger Monat');
		prev.addEventListener('click', function () { self.shift(-1); });
		var next = el('button', 'gcalmk-btn', '›');
		next.type = 'button';
		next.setAttribute('aria-label', 'Nächster Monat');
		next.addEventListener('click', function () { self.shift(1); });
		var today = el('button', 'gcalmk-btn gcalmk-today', 'Heute');
		today.type = 'button';
		today.addEventListener('click', function () {
			var n = new Date();
			self.year = n.getFullYear();
			self.month = n.getMonth();
			self.query = '';
			if (self.input) { self.input.value = ''; }
			self.render();
		});
		this.title = el('h3', 'gcalmk-title');
		this.title.setAttribute('aria-live', 'polite');
		this.nav.appendChild(prev);
		this.nav.appendChild(this.title);
		this.nav.appendChild(next);
		this.nav.appendChild(today);
		top.appendChild(this.nav);

		if (this.showSearch) {
			var box = el('div', 'gcalmk-search');
			this.input = el('input');
			this.input.type = 'search';
			this.input.placeholder = 'Termine suchen …';
			this.input.setAttribute('aria-label', 'Termine suchen');
			var timer = null;
			this.input.addEventListener('input', function () {
				clearTimeout(timer);
				timer = setTimeout(function () {
					self.query = self.input.value.trim();
					self.render();
				}, 200);
			});
			box.appendChild(this.input);
			top.appendChild(box);
		}
		this.root.appendChild(top);

		this.body = el('div', 'gcalmk-body');
		this.root.appendChild(this.body);
		this.status = el('p', 'gcalmk-status', 'Lade Termine …');
		this.body.appendChild(this.status);
	};

	Calendar.prototype.load = function () {
		var self = this;
		fetch(this.endpoint, { credentials: 'same-origin' })
			.then(function (r) {
				if (!r.ok) { throw new Error('HTTP ' + r.status); }
				return r.json();
			})
			.then(function (data) {
				self.events = data.events || [];
				self.tags = data.tags || [];
				if (self.tag) {
					var low = self.tag.toLowerCase();
					var found = '';
					self.tags.forEach(function (t) { if (t.toLowerCase() === low) { found = t; } });
					self.tag = found;
				}
				self.renderTags();
				self.render();
			})
			.catch(function () {
				self.body.textContent = '';
				self.body.appendChild(el('p', 'gcalmk-status', 'Die Termine konnten nicht geladen werden.'));
			});
	};

	Calendar.prototype.shift = function (delta) {
		var m = this.month + delta;
		this.year += Math.floor(m / 12);
		this.month = ((m % 12) + 12) % 12;
		this.render();
	};

	Calendar.prototype.renderTags = function () {
		var self = this;
		this.tagBar.textContent = '';
		if (!this.tags.length) { return; }
		var make = function (label, value) {
			var b = el('button', 'gcalmk-chip', label);
			b.type = 'button';
			b.setAttribute('aria-pressed', self.tag === value ? 'true' : 'false');
			b.addEventListener('click', function () {
				self.tag = value;
				self.renderTags();
				self.render();
			});
			self.tagBar.appendChild(b);
		};
		make('Alle', '');
		this.tags.forEach(function (t) { make(t, t); });
	};

	Calendar.prototype.filtered = function () {
		var tag = this.tag;
		return this.events.filter(function (ev) {
			return !tag || ev.tags.indexOf(tag) !== -1;
		});
	};

	Calendar.prototype.render = function () {
		this.body.textContent = '';
		var list = this.filtered();
		if (this.query.length >= 2) {
			this.nav.classList.add('gcalmk-hidden');
			this.renderSearch(list);
		} else {
			this.nav.classList.remove('gcalmk-hidden');
			this.renderMonth(list);
		}
	};

	Calendar.prototype.renderSearch = function (list) {
		var self = this;
		var q = this.query.toLowerCase();
		var hits = list.filter(function (ev) {
			var hay = (ev.title + ' ' + ev.location + ' ' + ev.description.replace(/<[^>]*>/g, ' ') + ' ' + ev.tags.join(' ')).toLowerCase();
			return hay.indexOf(q) !== -1;
		});
		var head = el('p', 'gcalmk-status', hits.length + (hits.length === 1 ? ' Treffer' : ' Treffer') + ' für „' + this.query + '“');
		this.body.appendChild(head);
		var ul = el('ul', 'gcalmk-results');
		hits.forEach(function (ev) {
			var li = el('li');
			var b = el('button', 'gcalmk-result');
			b.type = 'button';
			b.appendChild(el('span', 'gcalmk-r-date', shortDate(ev.startDate)));
			b.appendChild(el('span', 'gcalmk-r-title', ev.title));
			b.appendChild(el('span', 'gcalmk-r-time', timeText(ev)));
			b.addEventListener('click', function () { self.openEvent(ev, true); });
			li.appendChild(b);
			ul.appendChild(li);
		});
		this.body.appendChild(ul);
	};

	Calendar.prototype.renderMonth = function (list) {
		var self = this;
		var y = this.year, m = this.month;
		this.title.textContent = MONTHS[m] + ' ' + y;

		var lead = weekday(y, m, 1);
		var total = daysIn(y, m);
		var rows = Math.ceil((lead + total) / 7);

		// Termine je Tag.
		var byDay = {};
		var first = iso(y, m, 1), last = iso(y, m, total);
		list.forEach(function (ev) {
			if (ev.endDate < first || ev.startDate > last) { return; }
			var s = ev.startDate < first ? first : ev.startDate;
			var e = ev.endDate > last ? last : ev.endDate;
			var p = parseIso(s), q = parseIso(e);
			for (var d = p.d; d <= q.d; d++) {
				var key = iso(y, m, d);
				(byDay[key] = byDay[key] || []).push(ev);
			}
		});

		var grid = el('div', 'gcalmk-grid');
		grid.setAttribute('role', 'grid');
		grid.setAttribute('aria-label', MONTHS[m] + ' ' + y);
		WDAYS.forEach(function (w) {
			var h = el('div', 'gcalmk-wd', w);
			h.setAttribute('role', 'columnheader');
			grid.appendChild(h);
		});

		for (var i = 0; i < rows * 7; i++) {
			var d = i - lead + 1;
			var cell = el('div', 'gcalmk-cell');
			cell.setAttribute('role', 'gridcell');
			if (d < 1 || d > total) {
				cell.classList.add('gcalmk-out');
				grid.appendChild(cell);
				continue;
			}
			var key = iso(y, m, d);
			var evs = byDay[key] || [];
			if (key === this.todayIso) { cell.classList.add('gcalmk-is-today'); }
			if (!evs.length) { cell.classList.add('gcalmk-empty'); }
			if (i % 7 >= 5) { cell.classList.add('gcalmk-weekend'); }

			var num = el('div', 'gcalmk-dnum');
			num.appendChild(el('span', 'gcalmk-dshort', String(d)));
			num.appendChild(el('span', 'gcalmk-dlong', longDate(key)));
			cell.appendChild(num);

			evs.slice(0, MAX_PILLS).forEach(function (ev) {
				cell.appendChild(self.pill(ev, key));
			});
			if (evs.length > MAX_PILLS) {
				var more = el('button', 'gcalmk-more', '+ ' + (evs.length - MAX_PILLS) + ' weitere');
				more.type = 'button';
				(function (k, e) {
					more.addEventListener('click', function () { self.openDay(k, e); });
				})(key, evs);
				cell.appendChild(more);
			}
			grid.appendChild(cell);
		}
		this.body.appendChild(grid);

		var any = Object.keys(byDay).length > 0;
		if (!any) {
			this.body.appendChild(el('p', 'gcalmk-status', 'Keine Termine in diesem Monat' + (this.tag ? ' für „' + this.tag + '“' : '') + '.'));
		}
	};

	Calendar.prototype.pill = function (ev, key) {
		var self = this;
		var b = el('button', 'gcalmk-pill');
		b.type = 'button';
		var label = (!ev.allDay && key === ev.startDate ? ev.startTime + ' ' : '') + ev.title;
		b.textContent = label;
		b.title = label;
		if (ev.startDate !== ev.endDate) { b.classList.add('gcalmk-multi'); }
		b.addEventListener('click', function () { self.openEvent(ev, false); });
		return b;
	};

	// ---- Dialog -------------------------------------------------------

	Calendar.prototype.dialog = function (titleText, buildFn) {
		var self = this;
		var previous = document.activeElement;
		var overlay = el('div', 'gcalmk-overlay');
		var box = el('div', 'gcalmk-dialog');
		box.setAttribute('role', 'dialog');
		box.setAttribute('aria-modal', 'true');
		box.setAttribute('aria-label', titleText);
		var close = el('button', 'gcalmk-close', '×');
		close.type = 'button';
		close.setAttribute('aria-label', 'Schließen');
		var done = function () {
			document.removeEventListener('keydown', onKey);
			if (overlay.parentNode) { overlay.parentNode.removeChild(overlay); }
			if (previous && previous.focus) { previous.focus(); }
		};
		var onKey = function (e) { if (e.key === 'Escape') { done(); } };
		close.addEventListener('click', done);
		overlay.addEventListener('click', function (e) { if (e.target === overlay) { done(); } });
		document.addEventListener('keydown', onKey);
		box.appendChild(close);
		buildFn(box, done);
		overlay.appendChild(box);
		document.body.appendChild(overlay);
		close.focus();
		return done;
	};

	Calendar.prototype.openDay = function (key, evs) {
		var self = this;
		this.dialog(longDate(key), function (box, done) {
			box.appendChild(el('h4', 'gcalmk-d-title', longDate(key)));
			var ul = el('ul', 'gcalmk-results');
			evs.forEach(function (ev) {
				var li = el('li');
				var b = el('button', 'gcalmk-result');
				b.type = 'button';
				b.appendChild(el('span', 'gcalmk-r-time', timeText(ev)));
				b.appendChild(el('span', 'gcalmk-r-title', ev.title));
				b.addEventListener('click', function () { done(); self.openEvent(ev, false); });
				li.appendChild(b);
				ul.appendChild(li);
			});
			box.appendChild(ul);
		});
	};

	Calendar.prototype.openEvent = function (ev, fromSearch) {
		var self = this;
		this.dialog(ev.title, function (box, done) {
			box.appendChild(el('h4', 'gcalmk-d-title', ev.title));
			box.appendChild(el('p', 'gcalmk-d-when', dateRangeText(ev) + (ev.allDay ? '' : ', ' + timeText(ev))));
			if (ev.location) { box.appendChild(el('p', 'gcalmk-d-where', '📍 ' + ev.location)); }
			if (ev.tags.length) {
				var tl = el('p', 'gcalmk-d-tags');
				ev.tags.forEach(function (t) { tl.appendChild(el('span', 'gcalmk-tag', t)); });
				box.appendChild(tl);
			}
			if (ev.description) {
				var d = el('div', 'gcalmk-d-desc');
				d.innerHTML = ev.description; // serverseitig mit wp_kses bereinigt
				var links = d.querySelectorAll('a');
				for (var i = 0; i < links.length; i++) {
					links[i].target = '_blank';
					links[i].rel = 'noopener noreferrer';
				}
				box.appendChild(d);
			}
			var actions = el('p', 'gcalmk-d-actions');
			if (fromSearch) {
				var show = el('button', 'gcalmk-btn', 'Im Kalender anzeigen');
				show.type = 'button';
				show.addEventListener('click', function () {
					var p = parseIso(ev.startDate);
					self.year = p.y;
					self.month = p.m;
					self.query = '';
					if (self.input) { self.input.value = ''; }
					done();
					self.render();
				});
				actions.appendChild(show);
			}
			if (ev.link) {
				var a = el('a', 'gcalmk-btn', 'In Google Kalender öffnen');
				a.href = ev.link;
				a.target = '_blank';
				a.rel = 'noopener noreferrer';
				actions.appendChild(a);
			}
			if (actions.childNodes.length) { box.appendChild(actions); }
		});
	};

	function init() {
		var nodes = document.querySelectorAll('.gcalmk');
		for (var i = 0; i < nodes.length; i++) { new Calendar(nodes[i]); }
	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
