(function () {
	'use strict';

	var MONTHS = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
	var WDAYS = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
	var WDAYS_LONG = ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];
	var LANE_W = 8;     // Breite einer Zeitlinie in px

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
	var DEFAULT_COLOR = '#a83326';
	// Symbol für wiederkehrende Termine (Information "recurringEventId" von Google).
	function recIcon() {
		var s = el('span', 'gcalmk-rec', '↻');
		s.title = 'Wiederkehrender Termin';
		s.setAttribute('role', 'img');
		s.setAttribute('aria-label', 'Wiederkehrender Termin');
		return s;
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
		this.tagsOpen = false;
		this.colors = {};
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

		// Tag-Bereich: Kopfzeile (Auf-/Zuklappen + gewählter Tag) und aufklappbare Liste.
		this.tagWrap = el('div', 'gcalmk-tags');
		this.tagHead = el('div', 'gcalmk-taghead');
		this.tagPanel = el('div', 'gcalmk-tagpanel');
		this.tagPanel.id = 'gcalmk-tagpanel-' + Math.random().toString(36).slice(2, 8);
		this.tagPanel.setAttribute('role', 'group');
		this.tagPanel.setAttribute('aria-label', 'Nach Tag filtern');
		this.tagPanel.hidden = true;
		this.tagWrap.appendChild(this.tagHead);
		this.tagWrap.appendChild(this.tagPanel);
		if (this.showTags) { this.root.appendChild(this.tagWrap); }

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
				self.colors = data.colors || {};
				self.computeTags();
				if (self.tag) {
					// Vorgewählten Tag (Shortcode) auf die tatsächliche Schreibweise abbilden.
					var low = self.tag.toLowerCase();
					var found = '';
					self.events.forEach(function (ev) {
						ev.tags.forEach(function (t) { if (t.toLowerCase() === low) { found = t; } });
					});
					self.tag = found;
				}
				self.jumpIfSingle();
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

	// Nur Tags mit mindestens einem Termin ab heute, nach Häufigkeit (künftige Termine) sortiert.
	Calendar.prototype.computeTags = function () {
		var counts = {}, today = this.todayIso;
		this.events.forEach(function (ev) {
			if (ev.endDate < today) { return; }
			ev.tags.forEach(function (t) { counts[t] = (counts[t] || 0) + 1; });
		});
		this.tags = Object.keys(counts).sort(function (a, b) {
			return (counts[b] - counts[a]) || a.localeCompare(b, 'de');
		});
	};

	// Farbe eines Tags (in den Einstellungen änderbar); gleich für Punkt am Tag und Linie am Termin.
	Calendar.prototype.colorOf = function (tag) {
		return (tag && this.colors[tag]) || DEFAULT_COLOR;
	};
	Calendar.prototype.eventColor = function (ev) {
		return this.colorOf(ev.tags && ev.tags[0]);
	};

	Calendar.prototype.setTag = function (value) {
		this.tag = value;
		this.tagsOpen = false; // nach der Auswahl wieder einklappen
		this.jumpIfSingle();
		this.renderTags();
		this.render();
		var t = this.tagHead.querySelector('.gcalmk-tagtoggle');
		if (t) { t.focus(); }
	};

	Calendar.prototype.renderTags = function () {
		var self = this;
		this.tagHead.textContent = '';
		this.tagPanel.textContent = '';
		if (!this.tags.length && !this.tag) {
			this.tagWrap.hidden = true;
			return;
		}
		this.tagWrap.hidden = false;

		// Schaltfläche in neutralem Weiß; die Tag-Farbe zeigt nur ein Punkt.
		var chip = function (label, color, selected) {
			var b = el('button', 'gcalmk-chip');
			b.type = 'button';
			var dot = el('span', 'gcalmk-dot');
			dot.style.background = color;
			dot.setAttribute('aria-hidden', 'true');
			b.appendChild(dot);
			b.appendChild(el('span', 'gcalmk-chip-label', label));
			if (selected !== undefined) { b.setAttribute('aria-pressed', selected ? 'true' : 'false'); }
			return b;
		};

		var toggle = el('button', 'gcalmk-tagtoggle', 'Tags (' + this.tags.length + ') ' + (this.tagsOpen ? '▴' : '▾'));
		toggle.type = 'button';
		toggle.setAttribute('aria-expanded', this.tagsOpen ? 'true' : 'false');
		toggle.setAttribute('aria-controls', this.tagPanel.id);
		toggle.addEventListener('click', function () {
			self.tagsOpen = !self.tagsOpen;
			self.renderTags();
		});
		this.tagHead.appendChild(toggle);

		// Gewählter Tag bleibt dauerhaft sichtbar; Klick darauf hebt den Filter auf.
		if (this.tag) {
			var sel = chip(this.tag + '  ×', this.colorOf(this.tag), true);
			sel.classList.add('gcalmk-chip-selected');
			sel.setAttribute('aria-label', 'Tag ' + this.tag + ' aktiv, Filter aufheben');
			sel.addEventListener('click', function () { self.setTag(''); });
			this.tagHead.appendChild(sel);
		}

		this.tagPanel.hidden = !this.tagsOpen;
		this.tags.forEach(function (t) {
			var b = chip(t, self.colorOf(t), self.tag === t);
			b.addEventListener('click', function () { self.setTag(t); });
			self.tagPanel.appendChild(b);
		});
	};

	// Hat der gewählte Tag nur einen einzigen Termin, springt der Kalender in dessen Monat.
	Calendar.prototype.jumpIfSingle = function () {
		if (!this.tag) { return; }
		var list = this.filtered();
		if (list.length === 1) {
			var p = parseIso(list[0].startDate);
			this.year = p.y;
			this.month = p.m;
			this.query = '';
			if (this.input) { this.input.value = ''; }
		}
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
			var main = el('span', 'gcalmk-r-main');
			var ttl = el('span', 'gcalmk-r-title', ev.title);
			if (ev.recurring) { ttl.appendChild(document.createTextNode(' ')); ttl.appendChild(recIcon()); }
			main.appendChild(ttl);
			main.appendChild(el('span', 'gcalmk-r-time', timeText(ev)));
			b.appendChild(main);
			b.appendChild(el('span', 'gcalmk-r-chev', '›'));
			b.setAttribute('aria-label', ev.title + ', Details anzeigen');
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
			if (key === this.todayIso) { num.appendChild(el('span', 'gcalmk-todaybadge', 'Heute')); }
			num.appendChild(el('span', 'gcalmk-dlong', longDate(key)));
			cell.appendChild(num);

			var lay = self.dayLayout(evs, key);
			lay.allDay.forEach(function (ev) {
				var p = self.pill(ev, key);
				p.classList.add('gcalmk-pill-allday');
				p.style.borderLeftColor = self.eventColor(ev);
				cell.appendChild(p);
			});
			if (lay.timed.length) {
				// Alle Termine untereinander; farbige Linien laufen über alle Termine, die in ihre Zeit fallen.
				var stack = el('div', 'gcalmk-stack');
				lay.timed.forEach(function (ev, r) {
					var item = el('div', 'gcalmk-item');
					item.style.paddingLeft = (lay.laneCount * LANE_W) + 'px';
					lay.segs[r].forEach(function (s) {
						var seg = el('span', 'gcalmk-lane');
						seg.style.left = (s.lane * LANE_W) + 'px';
						seg.style.background = self.eventColor(s.ev); // Farbe des Tags
						if (s.first) { seg.classList.add('is-first'); }
						if (s.end) { seg.classList.add('is-end'); }
						item.appendChild(seg);
					});
					item.appendChild(self.pill(ev, key));
					stack.appendChild(item);
				});
				cell.appendChild(stack);
			}
			grid.appendChild(cell);
		}
		this.body.appendChild(grid);

		var any = Object.keys(byDay).length > 0;
		if (!any) {
			this.body.appendChild(el('p', 'gcalmk-status', 'Keine Termine in diesem Monat' + (this.tag ? ' für „' + this.tag + '“' : '') + '.'));
		}
	};

	// Tageslayout: ganztägige Termine, zeitgebundene Termine nach Beginn sortiert und je Termin eine
	// "Zeitlinie": sie läuft vom eigenen Eintrag über alle folgenden Einträge, die vor seinem Ende beginnen.
	Calendar.prototype.dayLayout = function (evs, key) {
		var allDay = [], timed = [];
		evs.forEach(function (ev) { (ev.allDay ? allDay : timed).push(ev); });
		var span = function (ev) {
			var s = key === ev.startDate ? ev.startTime : '00:00';
			var e = key === ev.endDate ? (ev.endTime > ev.startTime ? ev.endTime : ev.startTime) : '24:00';
			return { s: s, e: e };
		};
		timed.sort(function (a, b) {
			var x = span(a).s, y = span(b).s;
			return x < y ? -1 : (x > y ? 1 : 0);
		});
		var n = timed.length, last = [], lane = [], laneEnd = [], i, j, l;
		for (i = 0; i < n; i++) {
			var end = span(timed[i]).e;
			j = i;
			while (j + 1 < n && span(timed[j + 1]).s < end) { j++; }
			last[i] = j;
			l = 0;
			while (l < laneEnd.length && laneEnd[l] >= i) { l++; }
			lane[i] = l;
			laneEnd[l] = j;
		}
		var segs = [];
		for (var r = 0; r < n; r++) {
			segs[r] = [];
			for (i = 0; i <= r; i++) {
				if (last[i] >= r) { segs[r].push({ lane: lane[i], first: i === r, end: last[i] === r, ev: timed[i] }); }
			}
		}
		return { allDay: allDay, timed: timed, segs: segs, laneCount: Math.max(1, laneEnd.length) };
	};

	Calendar.prototype.pill = function (ev, key) {
		var self = this;
		var b = el('button', 'gcalmk-pill');
		b.type = 'button';
		var showTime = !ev.allDay && key === ev.startDate;
		if (showTime || ev.recurring) {
			var meta = el('span', 'gcalmk-p-time');
			if (showTime) { meta.appendChild(document.createTextNode(ev.startTime)); }
			if (ev.recurring) { meta.appendChild(recIcon()); }
			b.appendChild(meta);
		}
		b.appendChild(el('span', 'gcalmk-p-title', ev.title));
		b.title = (ev.allDay ? '' : ev.startTime + ' ') + ev.title;
		if (ev.allDay && ev.startDate !== ev.endDate) { b.classList.add('gcalmk-multi'); }
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

	Calendar.prototype.openEvent = function (ev, fromSearch) {
		var self = this;
		this.dialog(ev.title, function (box, done) {
			box.appendChild(el('h4', 'gcalmk-d-title', ev.title));
			box.appendChild(el('p', 'gcalmk-d-when', dateRangeText(ev) + (ev.allDay ? '' : ', ' + timeText(ev))));
			if (ev.recurring) { box.appendChild(el('p', 'gcalmk-d-rec', '↻ Wiederkehrender Termin')); }
			if (ev.location) { box.appendChild(el('p', 'gcalmk-d-where', '📍 ' + ev.location)); }
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
