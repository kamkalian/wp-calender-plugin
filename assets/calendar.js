(function () {
	'use strict';

	var MONTHS = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
	var WDAYS = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
	var WDAYS_LONG = ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];
	var LANE_W = 10;    // Breite einer Zeitlinie samt Abstand in px

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

	// ---- Ansichts-Einstellungen (Zahnrad), als Cookie gespeichert ---------------
	var PREF_COOKIE = 'gcalmk_prefs';
	function readPrefs() {
		var p = { bars: true, view: 'month' };
		var m = document.cookie.match(new RegExp('(?:^|; )' + PREF_COOKIE + '=([^;]*)'));
		if (m) {
			try {
				var o = JSON.parse(decodeURIComponent(m[1]));
				if (o && typeof o === 'object' && (o.bars === 0 || o.bars === false)) { p.bars = false; }
					if (o && o.view === 'list') { p.view = 'list'; }
			} catch (e) { /* ungültiges Cookie ignorieren */ }
		}
		return p;
	}
	function savePrefs(p) {
		try {
			document.cookie = PREF_COOKIE + '=' + encodeURIComponent(JSON.stringify({ bars: p.bars ? 1 : 0, view: p.view === 'list' ? 'list' : 'month' })) +
				'; max-age=31536000; path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
		} catch (e) { /* Cookies gesperrt */ }
	}
	// Feste Symbole (kein Fremdinhalt)
	var ICON_GEAR = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>';
	var ICON_PRINT = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>';
	function esc(s) {
		return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
	}

	// Abstand des fixierten Kopfbereichs zum oberen Fensterrand (Shortcode-Attribut oben="70", z. B. bei festem Theme-Header).
	function root_top(root) {
		var v = parseInt(root.getAttribute('data-top'), 10);
		return isNaN(v) ? null : Math.max(0, v);
	}

	function Calendar(root) {
		this.root = root;
		this.endpoint = root.getAttribute('data-endpoint');
		this.showSearch = root.getAttribute('data-search') !== '0';
		this.showTags = root.getAttribute('data-tags') !== '0';
		this.tag = root.getAttribute('data-tag') || '';
		this.area = root.getAttribute('data-area') || ''; // gewählter Bereich (Filter)
		this.showAreas = root.getAttribute('data-areas') !== '0';
		this.tagsOpen = false;
		this.tagRecurring = {};
		this.colors = {};
		this.areas = []; // Bereiche des Ausdrucks (Überschriften in Reihenfolge)
		this.query = '';
		this.events = [];
		this.tags = [];
		var now = new Date();
		this.year = now.getFullYear();
		this.month = now.getMonth();
		this.todayIso = iso(now.getFullYear(), now.getMonth(), now.getDate());
		this.prefs = readPrefs();
		this.build();
		this.applyPrefs();
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

		// Kopfbereich (Tags, Monatsnavigation, Suche, Wochentage): scrollt zuerst bis zum oberen Seitenrand
		// und bleibt dann stehen (position: sticky), während der Rest der Tabelle weiterscrollt.
		this.sticky = el('div', 'gcalmk-sticky');
		var stickyTop = root_top(this.root);
		if (stickyTop !== null) { this.root.style.setProperty('--gk-top', stickyTop + 'px'); }
		this.areaBar = el('div', 'gcalmk-areas');
		this.areaBar.setAttribute('role', 'group');
		this.areaBar.setAttribute('aria-label', 'Nach Bereich filtern');
		if (this.showAreas) { this.sticky.appendChild(this.areaBar); }
		if (this.showTags) { this.sticky.appendChild(this.tagWrap); }

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

		// Rechts oben: Suche, Drucken, Zahnrad (Ansicht einstellen)
		var tools = el('div', 'gcalmk-tools');
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
			tools.appendChild(box);
		}

		// Umschalter Kalender-/Listenansicht (Liste: nur die gefilterten Termine ab heute)
		var vw = el('div', 'gcalmk-viewsw');
		vw.setAttribute('role', 'group');
		vw.setAttribute('aria-label', 'Ansicht wählen');
		this.viewBtns = {};
		[['month', 'Kalender'], ['list', 'Liste']].forEach(function (v) {
			var b = el('button', 'gcalmk-viewbtn', v[1]);
			b.type = 'button';
			b.addEventListener('click', function () {
				self.prefs.view = v[0];
				savePrefs(self.prefs);
				self.render();
			});
			self.viewBtns[v[0]] = b;
			vw.appendChild(b);
		});
		tools.appendChild(vw);

		var printBtn = el('button', 'gcalmk-iconbtn');
		printBtn.type = 'button';
		printBtn.title = 'Angezeigten Monat drucken (DIN A4)';
		printBtn.setAttribute('aria-label', 'Angezeigten Monat drucken');
		printBtn.innerHTML = ICON_PRINT;
		printBtn.addEventListener('click', function () { self.printMonth(); });
		tools.appendChild(printBtn);

		var gearWrap = el('div', 'gcalmk-gearwrap');
		var gear = el('button', 'gcalmk-iconbtn');
		gear.type = 'button';
		gear.title = 'Ansicht einstellen';
		gear.setAttribute('aria-label', 'Ansicht einstellen');
		gear.setAttribute('aria-haspopup', 'true');
		gear.setAttribute('aria-expanded', 'false');
		gear.innerHTML = ICON_GEAR;
		var pop = el('div', 'gcalmk-pop');
		pop.hidden = true;
		pop.setAttribute('role', 'group');
		pop.setAttribute('aria-label', 'Ansicht einstellen');
		pop.appendChild(el('p', 'gcalmk-pop-title', 'Ansicht'));
		var opt = el('label', 'gcalmk-opt');
		var cb = el('input');
		cb.type = 'checkbox';
		cb.checked = this.prefs.bars;
		cb.addEventListener('change', function () {
			self.prefs.bars = cb.checked;
			savePrefs(self.prefs);
			self.applyPrefs();
		});
		opt.appendChild(cb);
		opt.appendChild(el('span', null, 'Farbige Balken anzeigen'));
		pop.appendChild(opt);
		var closePop = function () { pop.hidden = true; gear.setAttribute('aria-expanded', 'false'); };
		gear.addEventListener('click', function () {
			pop.hidden = !pop.hidden;
			gear.setAttribute('aria-expanded', pop.hidden ? 'false' : 'true');
		});
		document.addEventListener('click', function (e) { if (!gearWrap.contains(e.target)) { closePop(); } });
		gearWrap.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closePop(); gear.focus(); } });
		gearWrap.appendChild(gear);
		gearWrap.appendChild(pop);
		tools.appendChild(gearWrap);
		top.appendChild(tools);
		this.sticky.appendChild(top);

		// Wochentage gehören zum fixierten Kopf (nicht zum scrollenden Raster).
		this.weekdays = el('div', 'gcalmk-weekdays');
		this.weekdays.setAttribute('aria-hidden', 'true');
		WDAYS.forEach(function (w) { self.weekdays.appendChild(el('div', 'gcalmk-wd', w)); });
		this.sticky.appendChild(this.weekdays);
		this.root.appendChild(this.sticky);

		this.body = el('div', 'gcalmk-body');
		this.root.appendChild(this.body);
		this.status = el('p', 'gcalmk-status', 'Lade Termine …');
		this.body.appendChild(this.status);
	};

	// Farbige Balken (Linien und Punkte) ein-/ausblenden.
	Calendar.prototype.applyPrefs = function () {
		this.root.classList.toggle('gcalmk-nobars', !this.prefs.bars);
	};

	// ---- Drucken: alle eindeutigen Termine ab dem angezeigten Monat auf DIN A4 ---------------

	var DAY = 86400000;
	// Abgesagte Termine ("entfällt" in beliebiger Groß-/Kleinschreibung im Titel) kommen nicht in den Ausdruck.
	function isCancelled(ev) { return /entf(ä|ae)llt/i.test(ev.title); }
	function isoToTime(s) { var p = parseIso(s); return Date.UTC(p.y, p.m, p.d); }
	function timeToIso(t) { var d = new Date(t); return iso(d.getUTCFullYear(), d.getUTCMonth(), d.getUTCDate()); }
	function printDate(s) { var p = parseIso(s); return pad(p.d) + '.' + pad(p.m + 1) + '.' + String(p.y).slice(2); }
	function wdOf(s) { var p = parseIso(s); return weekday(p.y, p.m, p.d); }

	// Wochentage eines Eintrags: "Mo", "Di/Do", "Mo–Fr", "täglich"
	function weekdayText(wds) {
		if (wds.length === 7) { return 'täglich'; }
		if (wds.length >= 3 && wds[wds.length - 1] - wds[0] === wds.length - 1) { return WDAYS[wds[0]] + '–' + WDAYS[wds[wds.length - 1]]; }
		return wds.map(function (k) { return WDAYS[k]; }).join('/');
	}

	// Kurzes Datum "03.06."
	function shortDay(s) { var p = parseIso(s); return pad(p.d) + '.' + pad(p.m + 1) + '.'; }

	var WDAY_ADV = ['montags', 'dienstags', 'mittwochs', 'donnerstags', 'freitags', 'samstags', 'sonntags'];

	// Erkennt eine Wiederholung und beschreibt sie in Worten, sonst null. Toleriert Ausnahmen (Ferien, Feiertage):
	// Eine Regel gilt, wenn sie auf mindestens 85 % der Termine zutrifft.
	//   "jeden Montag" · "Mo–Fr" · "alle 14 Tage mittwochs" · "jeden 1. Mittwoch im Monat" · "jeden letzten Freitag im Monat"
	function recurrenceText(list, byWd) {
		var kinds = Object.keys(byWd).map(Number).sort(function (a, b) { return a - b; });
		var share = function (hit, total) { return total > 0 && hit / total >= 0.85; };

		if (kinds.length === 1) {
			var wd = kinds[0], n = list.length, i;
			// 1) fester Abstand in Wochen: 7 = jede Woche, 14 = alle 14 Tage, 28 = alle 28 Tage …
			var gaps = {}, gtot = 0;
			for (i = 1; i < n; i++) {
				var g = (isoToTime(list[i]) - isoToTime(list[i - 1])) / DAY;
				gaps[g] = (gaps[g] || 0) + 1;
				gtot++;
			}
			var best = 0, bestN = 0;
			Object.keys(gaps).forEach(function (k) { if (gaps[k] > bestN) { bestN = gaps[k]; best = +k; } });
			if (gtot >= 3 && best % 7 === 0 && share(bestN, gtot)) {
				return best === 7 ? 'jeden ' + WDAYS_LONG[wd] : 'alle ' + best + ' Tage ' + WDAY_ADV[wd];
			}
			// 2) monatlich am gleichen Wochentag der Woche: "jeden 2. Mittwoch im Monat" / "jeden letzten …"
			if (n >= 4) {
				var nth = {}, last = 0;
				list.forEach(function (s) {
					var p = parseIso(s), k = Math.ceil(p.d / 7);
					nth[k] = (nth[k] || 0) + 1;
					if (p.d + 7 > daysIn(p.y, p.m)) { last++; }
				});
				for (var k = 1; k <= 4; k++) {
					if (share(nth[k] || 0, n)) { return 'jeden ' + k + '. ' + WDAYS_LONG[wd] + ' im Monat'; }
				}
				if (share(last, n)) { return 'jeden letzten ' + WDAYS_LONG[wd] + ' im Monat'; }
			}
			return null;
		}

		// mehrere Wochentage: wöchentlich, wenn auf jeden Wochentag mindestens 85 % der Abstände genau 7 Tage betragen
		var tot = 0, w7 = 0;
		kinds.forEach(function (k) {
			var a = byWd[k];
			for (var j = 1; j < a.length; j++) {
				tot++;
				if (isoToTime(a[j]) - isoToTime(a[j - 1]) === 7 * DAY) { w7++; }
			}
		});
		if (tot >= 3 && share(w7, tot)) { return weekdayText(kinds); }
		return null;
	}

	// Text für die Spalte "Tage":
	//  - einzelner Tag: "So 24.01.27"
	//  - erkannte Wiederholung: Beschreibung und Zeitraum in einer Zeile, z. B. "alle 14 Tage mittwochs · 02.12.26 – 24.11.27"
	//  - sonst (unregelmäßig): die einzelnen Tage mit kurzem Datum, nach Jahren getrennt, z. B. "2027: 07.01., 04.02., 04.03."
	// Gibt fertiges (bereits maskiertes) HTML zurück.
	function datesText(list) {
		if (list.length === 1) { return { html: esc(WDAYS[wdOf(list[0])] + ' ' + printDate(list[0])) }; }

		var byWd = {};
		list.forEach(function (s) { var k = wdOf(s); (byWd[k] = byWd[k] || []).push(s); });
		var rec = recurrenceText(list, byWd);
		if (rec) {
			return { html: esc(rec + ' · ' + printDate(list[0]) + ' – ' + printDate(list[list.length - 1])) };
		}

		var years = {}, order = [];
		list.forEach(function (s) {
			var y = s.slice(0, 4);
			if (!years[y]) { years[y] = []; order.push(y); }
			years[y].push(s);
		});
		return { html: order.map(function (y) {
			var a = years[y], runs = [], i = 0, j;
			while (i < a.length) {
				j = i;
				while (j + 1 < a.length && isoToTime(a[j + 1]) - isoToTime(a[j]) === DAY) { j++; }
				runs.push([a[i], a[j]]);
				i = j + 1;
			}
			var text = runs.map(function (r) { return r[0] === r[1] ? shortDay(r[0]) : shortDay(r[0]) + '–' + shortDay(r[1]); }).join(', ');
			return '<div><span class="yr">' + esc(y) + ':</span> ' + esc(text) + '</div>';
		}).join('') };
	}

	// Titel für den Ausdruck: gleicher Eintrag trotz Zahl am Anfang ("10. "), Emojis, Satzzeichen oder Groß-/Kleinschreibung.
	// Titel mit "GESCHLOSSEN"/"ENTFÄLLT" werden über den Namen davor (bzw. danach) zusammengefasst, auch mit Zusätzen
	// wie "Frohe Weihnachten": "Geschäftsstelle GESCHLOSSEN", "Geschäftsstelle - GESCHLOSSEN 🤡" → ein Eintrag.
	function printTitle(title) {
		var t = String(title).replace(/^\s*\d+\s*\.\s+/, '')
			.replace(/[\u{1F000}-\u{1FFFF}\u{2600}-\u{27BF}\u{FE0F}\u{200D}]/gu, '')
			.replace(/\s+/g, ' ').trim();
		var alnum = function (s) { return s.toLowerCase().replace(/[^\p{L}\p{N}]+/gu, ' ').trim(); };
		var clean = function (s) { return s.replace(/^[\s\-–—:,;!(]+|[\s\-–—:,;!()]+$/g, ''); };
		var up = function (s) { return s.charAt(0).toUpperCase() + s.slice(1); };
		var m = t.match(/geschlossen|ge(?:ö|oe)ffnet|entf(?:ä|ae)llt/i);
		if (m) {
			var w = m[0].toLowerCase();
			var closed = w === 'geschlossen';
			var before = t.slice(0, m.index), after = t.slice(m.index + m[0].length);
			var base = alnum(before) ? before : after;
			if (alnum(base)) {
				// "Geschäftsstelle geöffnet" und "Geschäftsstelle geschlossen" gehören zum selben Eintrag "Geschäftsstelle".
				return { key: alnum(base) + (/^entf/.test(w) ? '|entfällt' : ''), display: up(clean(base)), closed: closed };
			}
		}
		return { key: alnum(t), display: up(t), closed: false };
	}

	// Eindeutige Termine (nach Titel) je Bereich ab dem ersten Tag des angezeigten Monats; jeder erscheint je Bereich genau einmal.
	// Der Bereich kommt aus den Tags des Termins (siehe Einstellungen). Termine ohne Tag mit Bereich werden nicht gedruckt.
	// Hat ein Termin mehrere Uhrzeiten, bekommt er je Uhrzeit einen Abschnitt ("slot") mit eigenen Tagen.
	Calendar.prototype.printEntries = function () {
		var from = this.todayIso, groups = {}, order = []; // nur Termine ab heute
		var hasAreas = this.areas.length > 0;
		this.filtered().forEach(function (ev) {
			if (isCancelled(ev) || ev.endDate < from) { return; }
			var evAreas = hasAreas ? (ev.areas || []) : ['']; // ohne eingerichtete Bereiche: eine Liste
			if (!evAreas.length) { return; }
			var tk = printTitle(ev.title);
			evAreas.forEach(function (area) {
				var key = area + '|' + tk.key;
				var g = groups[key];
				if (!g) {
					g = groups[key] = { area: area, title: tk.display, location: ev.location, tag: ev.tags[0] || '', slots: {}, slotOrder: [] };
					order.push(g);
				}
				// geschlossene Tage bilden einen Abschnitt ohne Uhrzeit
				var sk = tk.closed ? 'geschlossen' : (ev.allDay ? 'ganztags' : ev.startTime + '-' + ev.endTime);
				var s = g.slots[sk];
				if (!s) {
					s = g.slots[sk] = { allDay: ev.allDay, st: ev.startTime, et: ev.endTime, closed: tk.closed, dates: {} };
					g.slotOrder.push(s);
				}
				var t = isoToTime(ev.startDate < from ? from : ev.startDate), end = isoToTime(ev.endDate);
				for (; t <= end; t += DAY) { s.dates[timeToIso(t)] = true; }
			});
		});
		order.forEach(function (g) {
			g.slotOrder.forEach(function (s) { s.list = Object.keys(s.dates).sort(); });
			g.slotOrder.sort(function (a, b) {
				// "geschlossen" steht hinter den regulären Zeiten
				return ((a.closed ? 1 : 0) - (b.closed ? 1 : 0)) || (a.list[0] < b.list[0] ? -1 : (a.list[0] > b.list[0] ? 1 : 0)) || (a.st < b.st ? -1 : (a.st > b.st ? 1 : 0));
			});
			g.first = g.slotOrder.reduce(function (m, s) { return s.list[0] < m ? s.list[0] : m; }, g.slotOrder[0].list[0]);
		});
		order.sort(function (a, b) {
			return (a.first < b.first ? -1 : (a.first > b.first ? 1 : 0)) || a.title.localeCompare(b.title, 'de');
		});
		return order;
	};

	Calendar.prototype.buildPrintHtml = function () {
		var self = this;
		var entries = this.printEntries();
		var heading = 'ab ' + shortDate(this.todayIso);
		var lastDay = '';
		var seenTitles = {};

		// eine Tabelle (Termin | Zeit | Tage) für eine Liste von Einträgen
		var renderTable = function (list) {
			var body = list.map(function (g) {
				seenTitles[g.title.toLowerCase()] = true;
				var bar = self.prefs.bars ? ' style="border-left:4pt solid ' + esc(self.colorOf(g.tag)) + '"' : '';
				var n = g.slotOrder.length;
				var rows = g.slotOrder.map(function (s, i) {
					if (s.list[s.list.length - 1] > lastDay) { lastDay = s.list[s.list.length - 1]; }
					var time = s.allDay ? 'ganztägig' : s.st + (s.et && s.et !== s.st ? '–' + s.et : '') + ' Uhr';
					var dt = datesText(s.list);
					var cell = '';
					if (i === 0) {
						cell = '<td class="t" rowspan="' + n + '"' + bar + '><div class="ttl">' + esc(g.title) + '</div></td>'; // keine Beschreibung/kein Ort im Ausdruck
					}
					// geschlossene Tage: nur "GESCHLOSSEN", keine Uhrzeit
					var timeHtml = s.closed ? '<div class="closed">GESCHLOSSEN</div>' : esc(time);
					return '<tr' + (i < n - 1 ? ' class="more"' : '') + '>' + cell + '<td class="time">' + timeHtml + '</td><td class="days">' + dt.html + '</td></tr>';
				}).join('');
				return '<tbody>' + rows + '</tbody>'; // je Termin ein eigener Block (wird nicht über Seiten getrennt)
			}).join('');
			return '<table><thead><tr><th>Termin</th><th>Zeit</th><th>Tage</th></tr></thead>' + body + '</table>';
		};

		// Aufteilung nach Bereichen (Reihenfolge wie in den Einstellungen)
		var buckets = {};
		entries.forEach(function (g) { (buckets[g.area] = buckets[g.area] || []).push(g); });
		var n = entries.length, html;
		if (!n) {
			html = this.areas.length
				? '<p class="empty">Keine Termine mit einem Tag, der einem Bereich zugeordnet ist (Tags in der Beschreibung der Google-Termine, Bereiche in den Einstellungen).</p>'
				: '<p class="empty">Keine Termine in diesem Zeitraum.</p>';
		} else if (!this.areas.length) {
			html = renderTable(entries); // keine Bereiche eingerichtet: eine Liste
		} else {
			html = this.areas.filter(function (a) { return buckets[a]; })
				.map(function (a) { return '<h2>' + esc(a) + '</h2>' + renderTable(buckets[a]); }).join('');
		}

		var count = Object.keys(seenTitles).length;
		var sub = count + (count === 1 ? ' Termin' : ' verschiedene Termine') +
			(n ? ' · Zeitraum ' + printDate(this.todayIso) + ' – ' + printDate(lastDay) : '') +
			(this.area ? ' · Bereich: ' + esc(this.area) : '') + (this.tag ? ' · Tag: ' + esc(this.tag) : '') + ' · Stand ' + shortDate(this.todayIso);
		var css = '@page{size:A4 portrait;margin:14mm 12mm}' +
			'*{box-sizing:border-box}html,body{margin:0;padding:0}' +
			'body{font:10.5pt/1.35 Arial,Helvetica,sans-serif;color:#000}' +
			'h1{font-size:19pt;margin:0 0 1.5mm}.sub{color:#555;font-size:9.5pt;margin:0 0 4mm}' +
			'h2{font-size:14pt;margin:7mm 0 2mm;padding-bottom:1mm;border-bottom:1pt solid #000;page-break-after:avoid;break-after:avoid}' +
			'table{width:100%;border-collapse:collapse}' +
			'th{text-align:left;font-size:8.5pt;text-transform:uppercase;letter-spacing:.04em;color:#555;border-bottom:1.5pt solid #000;padding:1.5mm 2mm}' +
			'td{padding:1.8mm 2mm;border-bottom:.5pt solid #bbb;vertical-align:top}' +
			'tr.more td.time,tr.more td.days{border-bottom:.5pt dotted #ccc}' +
			'tbody{page-break-inside:avoid;break-inside:avoid}tr{page-break-inside:avoid;break-inside:avoid}thead{display:table-header-group}' +
			'td.t{width:42%}.ttl{font-weight:700}.loc{font-size:9pt;color:#444}.time{width:16%;white-space:nowrap}' +
			'.days .loc,.time .loc{font-size:inherit;color:inherit}' + // Datum, Uhrzeit und Zeitbereiche gleich groß
			'.yr{font-weight:700}.closed{font-weight:700}.empty{margin:10mm 0;color:#555}';
		return '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>Termine ' + esc(heading) + '</title><style>' + css + '</style></head><body>' +
			'<h1>Termine ' + esc(heading) + '</h1><p class="sub">' + sub + '</p>' + html +
			'</body></html>';
	};

	Calendar.prototype.printMonth = function () {
		var html = this.buildPrintHtml();
		var f = document.createElement('iframe');
		f.setAttribute('aria-hidden', 'true');
		f.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;opacity:0;pointer-events:none';
		document.body.appendChild(f);
		var d = f.contentWindow.document;
		d.open();
		d.write(html);
		d.close();
		var done = false;
		var go = function () {
			if (done) { return; }
			done = true;
			try { f.contentWindow.focus(); f.contentWindow.print(); } catch (e) { /* Druck nicht verfügbar */ }
			setTimeout(function () { if (f.parentNode) { f.parentNode.removeChild(f); } }, 60000);
		};
		f.onload = go;
		setTimeout(go, 300);
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
				self.areas = data.areas || [];
				if (self.area) {
					// Vorgewählten Bereich (Shortcode) auf die tatsächliche Schreibweise abbilden.
					var al = self.area.toLowerCase();
					self.area = '';
					self.areas.forEach(function (a) { if (a.toLowerCase() === al) { self.area = a; } });
				}
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
				self.renderAreas();
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

	// Bereiche als Filter über dem Kalender: nur Bereiche mit mindestens einem Termin ab heute, in der Reihenfolge der Einstellungen.
	Calendar.prototype.renderAreas = function () {
		var self = this, today = this.todayIso, has = {};
		this.areaBar.textContent = '';
		this.events.forEach(function (ev) {
			if (ev.endDate < today) { return; }
			(ev.areas || []).forEach(function (a) { has[a] = true; });
		});
		var list = this.areas.filter(function (a) { return has[a]; });
		this.areaBar.hidden = list.length === 0;
		list.forEach(function (a) {
			var b = el('button', 'gcalmk-areachip', a);
			b.type = 'button';
			b.setAttribute('aria-pressed', self.area === a ? 'true' : 'false');
			b.addEventListener('click', function () { self.setArea(self.area === a ? '' : a); });
			self.areaBar.appendChild(b);
		});
	};

	Calendar.prototype.setArea = function (value) {
		this.area = value;
		this.computeTags();
		this.jumpIfSingle();
		this.renderAreas();
		this.renderTags();
		this.render();
	};

	// Nur Tags mit mindestens einem Termin ab heute, alphabetisch sortiert.
	Calendar.prototype.computeTags = function () {
		var counts = {}, today = this.todayIso, rec = {}, area = this.area;
		this.events.forEach(function (ev) {
			if (ev.endDate < today) { return; }
			if (area && (ev.areas || []).indexOf(area) === -1) { return; } // nur Tags des gewählten Bereichs
			ev.tags.forEach(function (t) {
				counts[t] = (counts[t] || 0) + 1;
				if (ev.recurring) { rec[t] = true; } // Tag mit wiederkehrenden Terminen
			});
		});
		this.tagRecurring = rec;
		this.tags = Object.keys(counts).sort(function (a, b) {
			return a.localeCompare(b, 'de', { sensitivity: 'base' }); // alphabetisch
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
		var chip = function (label, color, selected, recurring) {
			var b = el('button', 'gcalmk-chip');
			b.type = 'button';
			var dot = el('span', 'gcalmk-dot');
			dot.style.background = color;
			dot.setAttribute('aria-hidden', 'true');
			b.appendChild(dot);
			b.appendChild(el('span', 'gcalmk-chip-label', label));
			if (recurring) { b.appendChild(recIcon()); }
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
			var sel = chip(this.tag, this.colorOf(this.tag), true, this.tagRecurring[this.tag]);
			sel.appendChild(el('span', 'gcalmk-x', '×'));
			sel.classList.add('gcalmk-chip-selected');
			sel.setAttribute('aria-label', 'Tag ' + this.tag + ' aktiv, Filter aufheben');
			sel.addEventListener('click', function () { self.setTag(''); });
			this.tagHead.appendChild(sel);
		}

		this.tagPanel.hidden = !this.tagsOpen;
		this.tags.forEach(function (t) {
			var b = chip(t, self.colorOf(t), self.tag === t, self.tagRecurring[t]);
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
		var tag = this.tag, area = this.area;
		return this.events.filter(function (ev) {
			return (!tag || ev.tags.indexOf(tag) !== -1) && (!area || (ev.areas || []).indexOf(area) !== -1);
		});
	};

	Calendar.prototype.render = function () {
		this.body.textContent = '';
		var list = this.filtered();
		var asList = this.prefs.view === 'list';
		for (var k in this.viewBtns) { this.viewBtns[k].setAttribute('aria-pressed', (k === 'list') === asList ? 'true' : 'false'); }
		if (asList) {
			this.nav.classList.add('gcalmk-hidden');
			this.weekdays.hidden = true;
			this.renderSearch(list);
		} else {
			this.nav.classList.remove('gcalmk-hidden');
			this.weekdays.hidden = false;
			if (this.query.length >= 2) {
				// Die Suche bleibt in der Kalenderansicht: nur passende Termine, mit Hinweis auf die Gesamtzahl.
				var q = this.query.toLowerCase();
				list = list.filter(function (ev) {
					return (ev.title + ' ' + ev.location + ' ' + ev.description.replace(/<[^>]*>/g, ' ') + ' ' + ev.tags.join(' ')).toLowerCase().indexOf(q) !== -1;
				});
				this.body.appendChild(el('p', 'gcalmk-status', list.length + (list.length === 1 ? ' Treffer' : ' Treffer') + ' für „' + this.query + '“ (alle Monate) – in der Ansicht „Liste“ siehst du sie untereinander.'));
			}
			this.renderMonth(list);
		}
	};

	Calendar.prototype.renderSearch = function (list) {
		var self = this;
		var q = this.query.toLowerCase();
		var asList = this.prefs.view === 'list', today = this.todayIso;
		var hits = list.filter(function (ev) {
			if (asList && ev.endDate < today) { return false; } // Listenansicht: nur Termine ab heute
			if (q.length < 2) { return true; }
			var hay = (ev.title + ' ' + ev.location + ' ' + ev.description.replace(/<[^>]*>/g, ' ') + ' ' + ev.tags.join(' ')).toLowerCase();
			return hay.indexOf(q) !== -1;
		});
		if (asList) {
			hits = hits.slice().sort(function (a, b) { return a.startDate < b.startDate ? -1 : (a.startDate > b.startDate ? 1 : 0); });
		}
		var head = el('p', 'gcalmk-status', q.length >= 2
			? hits.length + ' Treffer für „' + this.query + '“'
			: hits.length + (hits.length === 1 ? ' Termin' : ' Termine') + ' ab heute');
		this.body.appendChild(head);
		var ul = el('ul', 'gcalmk-results');
		hits.forEach(function (ev) {
			var li = el('li');
			var b = el('button', 'gcalmk-result');
			b.type = 'button';
			b.appendChild(el('span', 'gcalmk-r-date', shortDate(ev.startDate)));
			var main = el('span', 'gcalmk-r-main');
			main.appendChild(el('span', 'gcalmk-r-title', ev.title));
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
					// Punkte erst nach allen Linien, damit sie darüber liegen: zeigen, welche Linie zu diesem Termin gehört.
					lay.segs[r].forEach(function (s) {
						if (!s.dot) { return; }
						var dot = el('span', 'gcalmk-ldot');
						dot.style.left = (s.lane * LANE_W) + 'px';
						dot.style.background = self.eventColor(s.ev);
						item.appendChild(dot);
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
	// "Zeitlinie": sie läuft vom ersten Eintrag mit gleicher Startzeit über alle Einträge, die vor ihrem Ende beginnen.
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
		// Termine mit gleicher Startzeit stehen untereinander, ihre Linien beginnen aber alle beim obersten dieser Termine.
		var n = timed.length, first = [], last = [], lane = [], laneEnd = [], i, j, l;
		for (i = 0; i < n; i++) {
			var s0 = span(timed[i]).s;
			first[i] = (i > 0 && span(timed[i - 1]).s === s0) ? first[i - 1] : i;
			var end = span(timed[i]).e;
			j = i;
			while (j + 1 < n && span(timed[j + 1]).s < end) { j++; }
			last[i] = j;
			l = 0;
			while (l < laneEnd.length && laneEnd[l] >= first[i]) { l++; }
			lane[i] = l;
			laneEnd[l] = j;
		}
		var segs = [];
		for (var r = 0; r < n; r++) {
			segs[r] = [];
			for (i = 0; i < n; i++) {
				if (first[i] <= r && last[i] >= r) {
					// dot: Markierung auf der Linie an der Stelle des zugehörigen Termins
					segs[r].push({ lane: lane[i], first: first[i] === r, end: last[i] === r, dot: i === r, ev: timed[i] });
				}
			}
		}
		return { allDay: allDay, timed: timed, segs: segs, laneCount: Math.max(1, laneEnd.length) };
	};

	Calendar.prototype.pill = function (ev, key) {
		var self = this;
		var b = el('button', 'gcalmk-pill');
		b.type = 'button';
		if (!ev.allDay && key === ev.startDate) {
			b.appendChild(el('span', 'gcalmk-p-time', ev.startTime));
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
