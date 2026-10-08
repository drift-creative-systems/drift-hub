/**
 * Drift: Surface Hub — the /hub/ app. Vanilla JS, no build step.
 *
 * Routes (hash):  #/                       roster (all the user's artists)
 *                 #/artist/{id}            artist, first table
 *                 #/artist/{id}/{table}    artist, one table
 *
 * Every screen is drawn from the schema the server sends in /bootstrap, so a
 * field added to schemas/surface.php appears here with no JS changes.
 */
(function () {
	'use strict';

	var cfg = window.DriftHub || {};
	var main = document.getElementById('dh-main');
	var toasts = document.querySelector('.dh-toasts');
	var boot = null;          // { product, user, tables, artists, tz }
	var cache = {};           // "artistId|table" → records
	var dirty = false;        // unsaved edits in the open form

	if (!cfg.allowed || !main) { return; }

	/* ── Helpers ─────────────────────────────────────────────────────── */

	function h(tag, attrs, children) {
		var el = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			var v = attrs[k];
			if (v === null || v === undefined || v === false) { return; }
			if (k === 'class') { el.className = v; }
			else if (k === 'text') { el.textContent = v; }
			else if (k.indexOf('on') === 0) { el.addEventListener(k.slice(2), v); }
			else if (v === true) { el.setAttribute(k, ''); }
			else { el.setAttribute(k, v); }
		});
		[].concat(children || []).forEach(function (c) {
			if (c === null || c === undefined || c === false) { return; }
			el.appendChild(typeof c === 'string' || typeof c === 'number' ? document.createTextNode(String(c)) : c);
		});
		return el;
	}

	function api(path, opts) {
		opts = opts || {};
		var init = { method: opts.method || 'GET', credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce } };
		if (opts.body) {
			init.headers['Content-Type'] = 'application/json';
			init.body = JSON.stringify(opts.body);
		}
		return fetch(cfg.api + path, init).then(function (r) {
			return r.json().catch(function () { return {}; }).then(function (data) {
				if (!r.ok && !(opts.allowFail)) {
					var err = new Error((data && data.message) || 'Something went wrong (' + r.status + ').');
					err.fields = data && data.data && data.data.fields;
					throw err;
				}
				return data;
			});
		});
	}

	function toast(message, kind) {
		var t = h('div', { class: 'dh-toast dh-toast--' + (kind || 'ok'), role: kind === 'bad' ? 'alert' : 'status', text: message });
		toasts.appendChild(t);
		setTimeout(function () { t.remove(); }, kind === 'bad' ? 7000 : 3500);
	}

	function table(name) { return boot.tables.filter(function (t) { return t.name === name; })[0]; }
	function artist(id) { return boot.artists.filter(function (a) { return a.id === id; })[0]; }
	function field(t, name) { return t.fields.filter(function (f) { return f.name === name; })[0]; }
	function initials(name) { return (name || '?').split(/\s+/).filter(Boolean).slice(0, 2).map(function (w) { return w[0]; }).join('').toUpperCase(); }

	function ukDate(ymd) {
		var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(ymd || '');
		return m ? m[3] + '/' + m[2] + '/' + m[1] : '';
	}
	function ukDateTime(v) {
		var m = /^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})/.exec(v || '');
		return m ? m[3] + '/' + m[2] + '/' + m[1] + ' ' + m[4] + ':' + m[5] : '';
	}
	function ago(ts) {
		if (!ts) { return 'never'; }
		var s = Math.max(1, Math.round(Date.now() / 1000 - ts));
		if (s < 60) { return 'just now'; }
		if (s < 3600) { return Math.round(s / 60) + ' min ago'; }
		if (s < 86400) { return Math.round(s / 3600) + ' h ago'; }
		return Math.round(s / 86400) + ' days ago';
	}

	function navOrder() {
		// Everyday tables first, site settings last.
		return boot.tables.filter(function (t) { return !t.singleton; }).concat(boot.tables.filter(function (t) { return t.singleton; }));
	}

	function guard() {
		return !dirty || window.confirm('You have unsaved changes. Leave without saving?');
	}
	window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

	/* ── Router ──────────────────────────────────────────────────────── */

	var lastHash = location.hash;
	function route() {
		if (dirty && location.hash !== lastHash && !guard()) {
			history.replaceState(null, '', lastHash);
			return;
		}
		dirty = false;
		lastHash = location.hash;
		closeDrawer(true);
		var parts = location.hash.replace(/^#\/?/, '').split('/').map(decodeURIComponent);
		if (parts[0] === 'account') {
			showAccount();
		} else if (parts[0] === 'artist' && artist(parseInt(parts[1], 10))) {
			showArtist(parseInt(parts[1], 10), parts[2] || navOrder()[0].name);
		} else if (boot.artists.length === 1) {
			location.replace('#/artist/' + boot.artists[0].id);
		} else {
			showRoster();
		}
		main.focus({ preventScroll: true });
	}

	/* ── Roster ──────────────────────────────────────────────────────── */

	function avatar(a) {
		if (a.avatar) {
			return h('span', { class: 'dh-avatar dh-avatar--photo', 'aria-hidden': 'true' }, h('img', { src: a.avatar, alt: '' }));
		}
		return h('span', { class: 'dh-avatar', 'aria-hidden': 'true' }, a.logo ? h('img', { src: a.logo, alt: '' }) : initials(a.name));
	}

	function showRoster() {
		document.title = 'Artists — Drift: Surface Hub';
		var search = h('input', { class: 'dh-search', type: 'search', placeholder: 'Find an artist', 'aria-label': 'Find an artist' });
		var grid = h('div', { class: 'dh-cards' });

		function draw() {
			var q = search.value.trim().toLowerCase();
			grid.innerHTML = '';
			boot.artists.filter(function (a) { return !q || a.name.toLowerCase().indexOf(q) !== -1; }).forEach(function (a) {
				var unpublished = a.changed && a.changed > a.published;
				grid.appendChild(h('article', { class: 'dh-card' }, [
					h('div', { class: 'dh-card__top' }, [
						avatar(a),
						h('div', {}, [
							h('h2', { class: 'dh-card__name' }, h('a', { href: '#/artist/' + a.id, text: a.name })),
							a.labels.length ? h('p', { class: 'dh-card__label', text: a.labels.join(', ') }) : null
						])
					]),
					h('div', { class: 'dh-card__stats' }, [
						h('span', { class: 'dh-chip', text: a.upcomingGigs + ' upcoming gig' + (a.upcomingGigs === 1 ? '' : 's') }),
						a.newEnquiries ? h('span', { class: 'dh-chip dh-chip--new', text: a.newEnquiries + ' new enquir' + (a.newEnquiries === 1 ? 'y' : 'ies') }) : null,
						unpublished ? h('span', { class: 'dh-chip dh-chip--warn', text: 'Unpublished changes' }) : null
					]),
					h('div', { class: 'dh-card__foot' }, [
						h('span', { text: 'Published ' + ago(a.published) }),
						a.canPublish ? publishButton(a, true) : null
					])
				]));
			});
			if (!grid.children.length) { grid.appendChild(h('p', { class: 'dh-none', text: 'No artists match.' })); }
		}
		search.addEventListener('input', draw);

		main.innerHTML = '';
		main.appendChild(h('section', { class: 'dh-roster' }, [
			h('div', { class: 'dh-roster__head' }, [
				h('div', {}, [h('p', { class: 'dh-eyebrow', text: boot.product }), h('h1', { class: 'dh-h1', text: 'Your artists' })]),
				h('div', { class: 'dh-bar__right' }, [
					boot.artists.length > 6 ? search : null,
					cfg.admin ? h('a', { class: 'dh-btn dh-btn--ghost', href: cfg.admin, text: 'Manage artists' }) : null
				])
			]),
			boot.artists.length ? grid : h('div', { class: 'dh-none' }, [h('strong', { text: 'No artists yet' }), 'Ask your Drift contact to add your artists.'])
		]));
		draw();
	}

	/* ── Account ─────────────────────────────────────────────────────── */

	function showAccount() {
		document.title = 'Your account — Drift: Surface Hub';
		main.innerHTML = '';
		var wrap = h('section', { class: 'dh-roster dh-account' }, [
			boot.artists.length ? h('a', { class: 'dh-side__back', href: '#/', text: '← Back to artists' }) : null,
			h('div', { class: 'dh-roster__head' }, h('div', {}, [h('p', { class: 'dh-eyebrow', text: 'Drift: Surface Hub' }), h('h1', { class: 'dh-h1', text: 'Your account' })])),
			h('div', { class: 'dh-loading', text: 'Loading…' })
		]);
		main.appendChild(wrap);

		api('account').then(function (me) {
			var row = function (name, label, type, value, help, auto) {
				var id = 'acc-' + name;
				var input = h('input', { id: id, name: name, type: type, class: 'dh-input', autocomplete: auto, oninput: function () { dirty = true; field.classList.remove('is-invalid'); } });
				input.value = value || '';
				var field = h('div', { class: 'dh-field', 'data-field': name }, [h('label', { for: id, text: label }), input, help ? h('p', { class: 'dh-help', text: help }) : null]);
				return field;
			};
			var form = h('form', { class: 'dh-form', novalidate: true }, [
				h('section', { class: 'dh-group' }, [h('h2', { text: 'Your details' }), h('div', { class: 'dh-fields' }, [
					row('name', 'Name', 'text', me.name, '', 'name'),
					row('email', 'Email', 'email', me.email, 'You log in with this or your username, ' + me.login + '.', 'email')
				])]),
				h('section', { class: 'dh-group' }, [h('h2', { text: 'Change password' }), h('div', { class: 'dh-fields' }, [
					row('password', 'New password', 'password', '', 'At least 10 characters. Leave blank to keep your current one.', 'new-password'),
					row('confirm', 'Confirm new password', 'password', '', '', 'new-password')
				])]),
				h('section', { class: 'dh-group' }, [h('h2', { text: 'Confirm it\'s you' }), h('div', { class: 'dh-fields' }, [
					row('current', 'Current password', 'password', '', 'Needed to change your email or password.', 'current-password')
				])]),
				h('div', { class: 'dh-save' }, h('button', { type: 'submit', class: 'dh-btn', text: 'Save' }))
			]);
			var val = function (n) { return form.querySelector('[name="' + n + '"]').value; };
			form.addEventListener('submit', function (e) {
				e.preventDefault();
				if (val('password') !== val('confirm')) {
					showErrors(form, { message: 'The new passwords don\'t match.', fields: ['confirm'] });
					return;
				}
				var btn = form.querySelector('button[type=submit]');
				btn.disabled = true;
				api('account', { method: 'POST', body: { name: val('name'), email: val('email'), password: val('password'), current: val('current') } }).then(function (res) {
					dirty = false;
					if (res.relogin) {
						toast('Password changed. Please log in with your new password.');
						setTimeout(function () { location.href = res.login; }, 1800);
						return;
					}
					var who = document.querySelector('.dh-user');
					if (who) { who.textContent = res.name; }
					['current', 'password', 'confirm'].forEach(function (n) { form.querySelector('[name="' + n + '"]').value = ''; });
					var err = form.querySelector('.dh-error'); if (err) { err.remove(); }
					toast('Saved.');
				}).catch(function (err) { showErrors(form, err); })
					.then(function () { btn.disabled = false; });
			});
			wrap.querySelector('.dh-loading').replaceWith(form);
		}).catch(function (err) { wrap.querySelector('.dh-loading').textContent = err.message; });
	}

	/* ── Publish ─────────────────────────────────────────────────────── */

	function publishButton(a, small) {
		var btn = h('button', { type: 'button', class: 'dh-btn dh-btn--publish' + (small ? ' dh-btn--small' : ''), text: 'Publish website' });
		btn.addEventListener('click', function (e) {
			e.preventDefault();
			e.stopPropagation();
			if (dirty && !window.confirm('You have unsaved changes in this form. Publish without them?')) { return; }
			btn.disabled = true;
			btn.textContent = 'Publishing…';
			api('artists/' + a.id + '/publish', { method: 'POST', allowFail: true }).then(function (res) {
				if (res.artist) { Object.assign(a, res.artist); }
				toast(res.message || (res.ok ? 'Published.' : 'Publishing failed.'), res.ok ? 'ok' : 'bad');
				updateStatus(a);
			}).catch(function (err) { toast(err.message, 'bad'); })
				.then(function () { btn.disabled = false; btn.textContent = 'Publish website'; });
		});
		return btn;
	}

	function statusText(a) {
		if (a.changed && a.changed > a.published) { return { text: 'Changes not published yet', dirty: true }; }
		return { text: a.published ? 'Website up to date · published ' + ago(a.published) : 'Not published yet', dirty: !a.published };
	}

	function updateStatus(a) {
		var el = document.querySelector('.dh-status');
		if (!el) { return; }
		var s = statusText(a);
		el.textContent = s.text;
		el.classList.toggle('dh-status--dirty', s.dirty);
	}

	/* ── Artist workspace ────────────────────────────────────────────── */

	function showArtist(id, tableName) {
		var a = artist(id);
		var t = table(tableName) || navOrder()[0];
		document.title = t.label + ' — ' + a.name + ' — Drift: Surface Hub';

		var nav = h('ul', { class: 'dh-nav' }, navOrder().map(function (tb) {
			var count = tb.name === 'Enquiries' && a.newEnquiries ? h('span', { class: 'dh-nav__count', text: String(a.newEnquiries) }) : null;
			return h('li', {}, h('a', { href: '#/artist/' + a.id + '/' + encodeURIComponent(tb.name), 'aria-current': tb.name === t.name ? 'page' : null }, [
				h('span', { class: 'dh-nav__icon', 'aria-hidden': 'true', text: tb.icon }), tb.label, count
			]));
		}));

		var content = h('div', { class: 'dh-content' });
		var s = statusText(a);

		main.innerHTML = '';
		main.appendChild(h('div', { class: 'dh-ws' }, [
			h('aside', { class: 'dh-side', 'aria-label': a.name }, [
				boot.artists.length > 1 ? h('a', { class: 'dh-side__back', href: '#/', text: '← All artists' }) : null,
				h('div', { class: 'dh-side__artist' }, [avatar(a), h('strong', { text: a.name })]),
				h('nav', { 'aria-label': 'Content' }, nav)
			]),
			content
		]));

		content.appendChild(h('div', { class: 'dh-bar' }, [
			h('div', { class: 'dh-bar__left' }, [
				h('h1', { class: 'dh-h1', text: t.label }),
				h('span', { class: 'dh-status' + (s.dirty ? ' dh-status--dirty' : ''), text: s.text })
			]),
			h('div', { class: 'dh-bar__right' }, [
				a.site ? h('a', { class: 'dh-btn dh-btn--ghost', href: a.site, target: '_blank', rel: 'noopener', text: 'View website' }) : null,
				a.canPublish ? publishButton(a) : h('span', { class: 'dh-chip', title: 'Your web team connects the website to the hub', text: 'Website not connected yet' })
			])
		]));

		var body = h('div', { class: 'dh-loading', text: 'Loading…' });
		content.appendChild(body);

		load(a.id, t.name).then(function (records) {
			var view = t.singleton ? singletonView(a, t, records[0]) : listView(a, t, records);
			body.replaceWith(view);
		}).catch(function (err) {
			body.textContent = err.message;
		});
	}

	function load(artistId, tableName, fresh) {
		var key = artistId + '|' + tableName;
		if (cache[key] && !fresh) { return Promise.resolve(cache[key]); }
		return api('artists/' + artistId + '/' + encodeURIComponent(tableName)).then(function (rows) {
			cache[key] = rows;
			return rows;
		});
	}

	function markChanged(a) {
		a.changed = Math.floor(Date.now() / 1000);
		updateStatus(a);
	}

	/* ── List view ───────────────────────────────────────────────────── */

	function sortRows(t, rows) {
		if (!t.sort) { return rows.slice(); }
		var f = t.sort[0], dir = t.sort[1] === 'desc' ? -1 : 1;
		return rows.slice().sort(function (x, y) {
			var a = x.fields[f], b = y.fields[f];
			if (a === b) { return 0; }
			if (a === null || a === undefined || a === '') { return 1; }
			if (b === null || b === undefined || b === '') { return -1; }
			return (typeof a === 'number' && typeof b === 'number' ? a - b : String(a).localeCompare(String(b), 'en', { numeric: true })) * dir;
		});
	}

	function cell(a, t, f, value) {
		if (!f) { return ''; }
		if (value === null || value === undefined || value === '' || (Array.isArray(value) && !value.length)) { return h('span', { class: 'dh-help', text: '—' }); }
		switch (f.type) {
			case 'multipleAttachments':
				return value[0] && value[0].thumb ? h('img', { class: 'dh-thumb', src: value[0].thumb, alt: '' }) : h('span', { class: 'dh-thumb' });
			case 'date': return ukDate(value);
			case 'dateTime': case 'createdTime': return ukDateTime(value);
			case 'checkbox': return value ? '✓' : '';
			case 'currency': return (f.symbol || '£') + Number(value).toFixed(2).replace(/\.00$/, '');
			case 'rating': return '★★★★★'.slice(0, value);
			case 'multipleSelects': return value.join(', ');
			case 'multipleRecordLinks': return linkNames(a, f, value);
			case 'singleSelect': return h('span', { class: 'dh-chip', text: value });
			case 'multilineText': case 'richText': return h('span', { class: 'dh-clamp', text: value });
			default: return String(value);
		}
	}

	function linkNames(a, f, ids) {
		var target = table(f.link.table);
		var span = h('span', { text: '…' });
		load(a.id, target.name).then(function (rows) {
			span.textContent = ids.map(function (id) {
				var r = rows.filter(function (x) { return x.id === id; })[0];
				return r ? (r.fields[target.primary] || 'Untitled') : '';
			}).filter(Boolean).join(', ');
		});
		return span;
	}

	function listView(a, t, records) {
		var wrap = h('div', {});
		var head = h('div', { class: 'dh-bar' }, [
			h('p', { class: 'dh-help', text: t.status ? 'Rows marked "Hidden" are kept here but not shown on the website.' : '' }),
			t.inbox ? null : h('button', { type: 'button', class: 'dh-btn', text: '+ ' + t.addLabel, onclick: function () { openEditor(a, t, null); } })
		]);
		wrap.appendChild(head);

		var rows = sortRows(t, records);
		if (!rows.length) {
			wrap.appendChild(h('div', { class: 'dh-panel' }, h('div', { class: 'dh-none' }, [
				h('strong', { text: t.inbox ? 'Nothing here yet' : 'No ' + t.label.toLowerCase() + ' yet' }),
				t.inbox ? 'Messages from the website arrive here.' : 'Add the first one with "' + t.addLabel + '".'
			])));
			return wrap;
		}

		var cols = t.columns;
		var thead = h('thead', {}, h('tr', {}, cols.map(function (c, i) {
			return h('th', { scope: 'col', class: i > 1 ? 'dh-col-opt' : null, text: c });
		}).concat(t.status ? [h('th', { scope: 'col', text: 'On website' })] : [])));

		var titleCol = cols.indexOf(t.primary) !== -1 ? t.primary : cols.filter(function (c) { var f = field(t, c); return f && f.type !== 'multipleAttachments' && f.type !== 'multipleRecordLinks'; })[0];
		var tbody = h('tbody', {}, rows.map(function (r) {
			var hidden = t.status && !r.fields[t.status];
			var tr = h('tr', { class: hidden ? 'is-hidden' : null }, cols.map(function (c, i) {
				var f = field(t, c);
				var content = cell(a, t, f, r.fields[c]);
				if (c === titleCol) {
					content = h('a', { class: 'dh-row-title', href: '#', onclick: function (e) { e.preventDefault(); openEditor(a, t, r); } }, [content || 'Untitled']);
				}
				return h('td', { class: i > 1 ? 'dh-col-opt' : null }, content);
			}).concat(t.status ? [h('td', {}, hidden ? h('span', { class: 'dh-chip', text: 'Hidden' }) : h('span', { class: 'dh-chip dh-chip--ok', text: 'Shown' }))] : []));
			tr.addEventListener('click', function (e) { if (!e.target.closest('a,button')) { openEditor(a, t, r); } });
			return tr;
		}));

		wrap.appendChild(h('div', { class: 'dh-panel' }, h('table', { class: 'dh-table' }, [thead, tbody])));
		return wrap;
	}

	/* ── Field inputs ────────────────────────────────────────────────── */

	/**
	 * Builds one field. Returns { el, get() } — get() gives the value to save.
	 */
	function input(a, t, f, value, isNew) {
		var id = 'f-' + Math.random().toString(36).slice(2, 9);
		var ro = !!f.readonly;
		var wide = ['multilineText', 'richText', 'multipleAttachments', 'multipleSelects', 'multipleRecordLinks'].indexOf(f.type) !== -1;
		var wrap = h('div', { class: 'dh-field' + (wide ? ' dh-field--wide' : ''), 'data-field': f.name });
		var labelEl = h('label', { for: id }, [f.name, f.required ? h('span', { class: 'dh-req', 'aria-hidden': 'true', text: ' *' }) : null]);
		var help = f.help ? h('p', { class: 'dh-help', id: id + '-h', text: f.help }) : null;
		var control, get;
		var mark = function () { dirty = true; wrap.classList.remove('is-invalid'); };

		switch (f.type) {
			case 'multilineText':
			case 'richText':
				control = h('textarea', { id: id, class: 'dh-input' + (f.type === 'richText' ? ' dh-input--rich' : ''), readonly: ro, 'aria-describedby': help ? id + '-h' : null, oninput: mark });
				control.value = value || '';
				get = function () { return control.value; };
				break;

			case 'checkbox':
				control = h('input', { id: id, type: 'checkbox', disabled: ro, onchange: mark });
				control.checked = isNew && (value === null || value === undefined) ? !!f.default : !!value;
				labelEl = h('label', { class: 'dh-switch', for: id }, [control, f.name]);
				get = function () { return control.checked; };
				wrap.appendChild(labelEl);
				if (help) { wrap.appendChild(help); }
				return { el: wrap, get: get };

			case 'date':
				control = h('input', { id: id, type: 'date', class: 'dh-input', readonly: ro, oninput: mark });
				control.value = value || '';
				get = function () { return control.value; };
				break;

			case 'dateTime':
				control = h('input', { id: id, type: 'datetime-local', class: 'dh-input', readonly: ro, oninput: mark });
				control.value = value || '';
				get = function () { return control.value; };
				break;

			case 'number':
			case 'currency':
				control = h('input', { id: id, type: 'number', class: 'dh-input', step: f.type === 'currency' ? '0.01' : '1', min: '0', inputmode: 'decimal', readonly: ro, oninput: mark });
				control.value = value === null || value === undefined ? '' : value;
				get = function () { return control.value === '' ? null : Number(control.value); };
				break;

			case 'rating':
				var current = value || 0;
				control = h('div', { class: 'dh-stars', role: 'radiogroup', 'aria-label': f.name });
				var draw = function () {
					[].forEach.call(control.children, function (b, i) { b.classList.toggle('is-on', i < current); b.setAttribute('aria-checked', i + 1 === current ? 'true' : 'false'); });
				};
				for (var i = 1; i <= (f.max || 5); i++) {
					(function (n) {
						control.appendChild(h('button', { type: 'button', role: 'radio', 'aria-label': n + ' star' + (n > 1 ? 's' : ''), text: '★', onclick: function () { current = current === n ? 0 : n; draw(); mark(); } }));
					})(i);
				}
				draw();
				labelEl = h('span', { class: 'dh-label', text: f.name });
				get = function () { return current || null; };
				break;

			case 'singleSelect':
				control = h('select', { id: id, class: 'dh-input', disabled: ro, onchange: mark }, [h('option', { value: '', text: '—' })].concat((f.choices || []).map(function (c) { return h('option', { value: c, text: c }); })));
				control.value = value || (isNew && f.default ? f.default : '');
				if (value && (f.choices || []).indexOf(value) === -1) { control.appendChild(h('option', { value: value, text: value })); control.value = value; }
				get = function () { return control.value || null; };
				break;

			case 'multipleSelects':
				var picked = (value || []).slice();
				control = h('div', { class: 'dh-checks', role: 'group', 'aria-labelledby': id + '-l' }, (f.choices || []).map(function (c) {
					var cb = h('input', { type: 'checkbox', value: c, disabled: ro, onchange: function () { picked = cb.checked ? picked.concat(c) : picked.filter(function (p) { return p !== c; }); mark(); } });
					cb.checked = picked.indexOf(c) !== -1;
					return h('label', { class: 'dh-switch' }, [cb, c]);
				}));
				labelEl = h('span', { class: 'dh-label', id: id + '-l', text: f.name });
				get = function () { return picked; };
				break;

			case 'multipleAttachments':
				var items = (value || []).slice();
				control = h('div', { class: 'dh-media' });
				var drawMedia = function () {
					control.innerHTML = '';
					items.forEach(function (it, idx) {
						control.appendChild(h('div', { class: 'dh-media__item' }, [
							it.thumb && it.mime.indexOf('image') === 0 ? h('img', { src: it.thumb, alt: it.name }) : h('div', { class: 'dh-media__file', text: it.name }),
							ro ? null : h('button', { type: 'button', class: 'dh-media__remove', 'aria-label': 'Remove ' + it.name, text: '×', onclick: function () { items.splice(idx, 1); drawMedia(); mark(); } })
						]));
					});
					if (!ro && (!f.max || items.length < f.max)) {
						control.appendChild(h('button', { type: 'button', class: 'dh-btn dh-btn--ghost dh-btn--small', text: items.length ? (f.max === 1 ? 'Replace' : 'Add more') : (f.max === 1 ? 'Choose file' : 'Add files'), onclick: pick }));
					}
				};
				var pick = function () {
					if (!window.wp || !wp.media) { toast('The media library didn\'t load. Refresh the page.', 'bad'); return; }
					// Uploads are tagged with this artist, and the library only lists this artist's media (class-media.php).
					if (wp.Uploader && wp.Uploader.defaults) {
						wp.Uploader.defaults.multipart_params = wp.Uploader.defaults.multipart_params || {};
						wp.Uploader.defaults.multipart_params.drift_hub_artist = a.id;
					}
					var frame = wp.media({ title: f.name, multiple: f.max === 1 ? false : 'add', library: { drift_hub_artist: a.id }, button: { text: 'Use ' + (f.max === 1 ? 'this' : 'these') } });
					frame.on('select', function () {
						var chosen = frame.state().get('selection').toJSON().map(function (m) {
							var thumb = (m.sizes && (m.sizes.medium || m.sizes.thumbnail || m.sizes.full)) ? (m.sizes.medium || m.sizes.thumbnail || m.sizes.full).url : (m.icon || '');
							return { id: m.id, url: m.url, thumb: thumb, name: m.filename || m.title, mime: m.mime || '' };
						});
						items = f.max === 1 ? chosen.slice(0, 1) : items.concat(chosen.filter(function (c) { return !items.some(function (i) { return i.id === c.id; }); }));
						if (f.max) { items = items.slice(0, f.max); }
						drawMedia();
						mark();
					});
					frame.open();
				};
				drawMedia();
				labelEl = h('span', { class: 'dh-label', text: f.name });
				get = function () { return items.map(function (i) { return i.id; }); };
				break;

			case 'multipleRecordLinks':
				var target = table(f.link.table);
				labelEl = h('label', { for: id, text: f.name });
				if (f.link.inverse) {
					control = h('ul', { class: 'dh-linklist' }, h('li', { text: '…' }));
					load(a.id, target.name).then(function (rows) {
						control.innerHTML = '';
						var names = (value || []).map(function (rid) { var r = rows.filter(function (x) { return x.id === rid; })[0]; return r ? r.fields[target.primary] : null; }).filter(Boolean);
						if (!names.length) { control.replaceWith(h('p', { class: 'dh-help', text: 'None yet.' })); return; }
						names.forEach(function (n) { control.appendChild(h('li', { text: n })); });
					});
					get = function () { return undefined; };
					break;
				}
				control = h('select', { id: id, class: 'dh-input', multiple: f.max === 1 ? null : true, onchange: mark }, h('option', { value: '', text: 'Loading…' }));
				load(a.id, target.name).then(function (rows) {
					control.innerHTML = '';
					if (f.max === 1) { control.appendChild(h('option', { value: '', text: '—' })); }
					sortRows(target, rows).forEach(function (r) {
						var o = h('option', { value: r.id, text: r.fields[target.primary] || 'Untitled' });
						o.selected = (value || []).indexOf(r.id) !== -1;
						control.appendChild(o);
					});
					if (!rows.length) { control.appendChild(h('option', { value: '', text: 'Add some ' + target.label.toLowerCase() + ' first' })); }
				});
				get = function () { return [].filter.call(control.options, function (o) { return o.selected && o.value; }).map(function (o) { return o.value; }); };
				break;

			case 'formula':
			case 'createdTime':
				control = h('input', { id: id, class: 'dh-input', readonly: true, value: f.type === 'createdTime' ? ukDateTime(value) : (value || '') });
				get = function () { return undefined; };
				break;

			default:
				var type = { url: 'url', email: 'email', phoneNumber: 'tel' }[f.type] || 'text';
				control = h('input', { id: id, type: type, class: 'dh-input', readonly: ro, 'aria-describedby': help ? id + '-h' : null, oninput: mark, placeholder: f.type === 'url' ? 'https://' : null });
				control.value = value || '';
				get = function () { return control.value; };
				if (f.format === 'colour' && !ro) {
					var picker = h('input', { type: 'color', 'aria-label': f.name + ' picker', oninput: function () { control.value = picker.value; mark(); } });
					picker.value = /^#[0-9a-f]{6}$/i.test(control.value) ? control.value : '#000000';
					control.addEventListener('input', function () { if (/^#[0-9a-f]{6}$/i.test(control.value)) { picker.value = control.value; } });
					control = h('div', { class: 'dh-colour' }, [picker, control]);
				}
		}

		if (ro && f.type !== 'formula' && f.type !== 'createdTime') { get = function () { return undefined; }; }
		wrap.appendChild(labelEl);
		wrap.appendChild(control);
		if (help) { wrap.appendChild(help); }
		return { el: wrap, get: get };
	}

	function collect(inputs) {
		var out = {};
		inputs.forEach(function (i) {
			var v = i.get();
			if (v !== undefined) { out[i.name] = v === '' ? null : v; }
		});
		return out;
	}

	function showErrors(container, err) {
		var box = container.querySelector('.dh-error');
		if (box) { box.remove(); }
		container.prepend(h('p', { class: 'dh-error', role: 'alert', text: err.message }));
		(err.fields || []).forEach(function (name) {
			var el = container.querySelector('[data-field="' + CSS.escape(name) + '"]');
			if (el) { el.classList.add('is-invalid'); }
		});
		container.scrollTop = 0;
	}

	/* ── Site settings (single record) ───────────────────────────────── */

	function singletonView(a, t, record) {
		var form = h('form', { class: 'dh-form', novalidate: true });
		var groups = {};
		var inputs = [];
		t.fields.forEach(function (f) {
			var g = f.group || 'Details';
			if (!groups[g]) {
				groups[g] = h('div', { class: 'dh-fields' });
				form.appendChild(h('section', { class: 'dh-group' }, [h('h2', { text: g }), groups[g]]));
			}
			var i = input(a, t, f, record.fields[f.name], false);
			i.name = f.name;
			inputs.push(i);
			groups[g].appendChild(i.el);
		});
		var save = h('button', { type: 'submit', class: 'dh-btn', text: 'Save settings' });
		form.appendChild(h('div', { class: 'dh-save' }, save));
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			save.disabled = true;
			api('records/' + record.id, { method: 'POST', body: { fields: collect(inputs) } }).then(function (rec) {
				cache[a.id + '|' + t.name] = [rec];
				dirty = false;
				markChanged(a);
				toast('Saved. Press "Publish website" when you\'re ready.');
				var err = form.querySelector('.dh-error'); if (err) { err.remove(); }
			}).catch(function (err) { showErrors(form, err); toast(err.message, 'bad'); })
				.then(function () { save.disabled = false; });
		});
		return form;
	}

	/* ── Editor drawer ───────────────────────────────────────────────── */

	var drawer = null, lastFocus = null;

	function closeDrawer(force) {
		if (!drawer) { return true; }
		if (!force && !guard()) { return false; }
		drawer.remove();
		drawer = null;
		dirty = false;
		document.removeEventListener('keydown', onKey);
		if (lastFocus && document.body.contains(lastFocus)) { lastFocus.focus(); }
		return true;
	}

	function onKey(e) {
		if (e.key === 'Escape' && !document.querySelector('.media-modal')) { closeDrawer(); }
		if (e.key === 'Tab' && drawer) {
			var f = drawer.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
			f = [].filter.call(f, function (el) { return !el.disabled && el.offsetParent !== null; });
			if (!f.length) { return; }
			if (e.shiftKey && document.activeElement === f[0]) { e.preventDefault(); f[f.length - 1].focus(); }
			else if (!e.shiftKey && document.activeElement === f[f.length - 1]) { e.preventDefault(); f[0].focus(); }
		}
	}

	function openEditor(a, t, record) {
		if (drawer && !closeDrawer()) { return; }
		lastFocus = document.activeElement;
		var isNew = !record;
		var titleId = 'dh-d-' + Date.now();
		var body = h('div', { class: 'dh-drawer__body' });
		var fields = h('div', { class: 'dh-fields' });
		var inputs = [];
		t.fields.forEach(function (f) {
			if (isNew && (f.type === 'formula' || f.type === 'createdTime' || (f.link && f.link.inverse))) { return; }
			var value = record ? record.fields[f.name] : undefined;
			var i = input(a, t, f, value, isNew);
			i.name = f.name;
			inputs.push(i);
			fields.appendChild(i.el);
		});
		body.appendChild(fields);

		var save = h('button', { type: 'button', class: 'dh-btn', text: isNew ? t.addLabel : 'Save changes' });
		var del = isNew ? null : h('button', { type: 'button', class: 'dh-btn dh-btn--danger', text: 'Delete' });
		var title = isNew ? t.addLabel : (record.fields[t.primary] || 'Edit');

		drawer = h('div', { class: 'dh-drawer', onclick: function (e) { if (e.target === drawer) { closeDrawer(); } } },
			h('div', { class: 'dh-drawer__panel', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': titleId }, [
				h('div', { class: 'dh-drawer__head' }, [
					h('h2', { id: titleId, text: title }),
					h('button', { type: 'button', class: 'dh-close', 'aria-label': 'Close', text: '×', onclick: function () { closeDrawer(); } })
				]),
				body,
				h('div', { class: 'dh-drawer__foot' }, [del || h('span'), h('div', { class: 'dh-bar__right' }, [h('button', { type: 'button', class: 'dh-btn dh-btn--ghost', text: 'Cancel', onclick: function () { closeDrawer(); } }), save])])
			]));
		document.body.appendChild(drawer);
		document.addEventListener('keydown', onKey);
		dirty = false;
		var first = drawer.querySelector('input:not([type=checkbox]):not([readonly]), textarea:not([readonly]), select');
		(first || drawer.querySelector('.dh-close')).focus();

		var key = a.id + '|' + t.name;
		save.addEventListener('click', function () {
			save.disabled = true;
			var req = isNew
				? api('artists/' + a.id + '/' + encodeURIComponent(t.name), { method: 'POST', body: { fields: collect(inputs) } })
				: api('records/' + record.id, { method: 'POST', body: { fields: collect(inputs) } });
			req.then(function (rec) {
				var rows = (cache[key] || []).filter(function (r) { return r.id !== rec.id; });
				rows.push(rec);
				cache[key] = rows;
				invalidateLinked(a, t);
				dirty = false;
				markChanged(a);
				closeDrawer(true);
				toast(isNew ? 'Added.' : 'Saved.');
				showArtist(a.id, t.name);
			}).catch(function (err) { showErrors(body, err); })
				.then(function () { save.disabled = false; });
		});

		if (del) {
			del.addEventListener('click', function () {
				if (!window.confirm('Delete "' + title + '"? This can\'t be undone. To hide it from the website instead, untick "' + (t.status || 'Show on Site') + '".')) { return; }
				api('records/' + record.id, { method: 'DELETE' }).then(function () {
					cache[key] = (cache[key] || []).filter(function (r) { return r.id !== record.id; });
					invalidateLinked(a, t);
					markChanged(a);
					closeDrawer(true);
					toast('Deleted.');
					showArtist(a.id, t.name);
				}).catch(function (err) { toast(err.message, 'bad'); });
			});
		}
	}

	/** Linked tables (Releases ↔ Tracks) show each other's names: refetch next time. */
	function invalidateLinked(a, t) {
		boot.tables.forEach(function (other) {
			other.fields.forEach(function (f) {
				if (f.link && (f.link.table === t.name || other.name === t.name)) {
					delete cache[a.id + '|' + other.name];
					delete cache[a.id + '|' + f.link.table];
				}
			});
		});
	}

	/* ── Start ───────────────────────────────────────────────────────── */

	api('bootstrap').then(function (data) {
		boot = data;
		window.addEventListener('hashchange', route);
		route();
	}).catch(function (err) {
		main.innerHTML = '';
		main.appendChild(h('div', { class: 'dh-empty' }, [h('h1', { text: 'The hub didn\'t load' }), h('p', { text: err.message })]));
	});
})();
