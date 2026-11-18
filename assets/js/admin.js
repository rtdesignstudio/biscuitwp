(function () {
	'use strict';

	function reindex(container) {
		var rows = container.querySelectorAll('[data-cmp-cat-row]');
		rows.forEach(function (row, i) {
			row.querySelectorAll('[name]').forEach(function (el) {
				el.name = el.name.replace(/cmp_cats\[[^\]]*\]/, 'cmp_cats[' + i + ']');
			});
		});
	}

	var uid = 0;
	function nextId() { uid += 1; return 'n' + Date.now() + uid; }

	// Services screen: custom service rows and their cookie rows.
	function onServicesClick(e) {
		var add = e.target.closest('[data-cmp-add-custom]');
		if (add) {
			var tpl = document.getElementById('cmp-custom-template');
			var wrap = document.querySelector('[data-cmp-customs]');
			if (!tpl || !wrap) { return true; }
			var div = document.createElement('div');
			div.innerHTML = tpl.innerHTML.replace(/__i__/g, nextId());
			if (div.firstElementChild) { wrap.appendChild(div.firstElementChild); }
			return true;
		}
		var del = e.target.closest('[data-cmp-del-custom]');
		if (del) {
			var row = del.closest('[data-cmp-custom-row]');
			if (row) { row.parentNode.removeChild(row); }
			return true;
		}
		var addCk = e.target.closest('[data-cmp-add-svc-cookie]');
		if (addCk) {
			var box = addCk.closest('[data-cmp-ck-prefix]');
			var body = box.querySelector('[data-cmp-svc-cookies-body]');
			var p = box.getAttribute('data-cmp-ck-prefix') + '[' + nextId() + ']';
			var tr = document.createElement('tr');
			tr.setAttribute('data-cmp-svc-cookie-row', '');
			['name', 'duration', 'purpose'].forEach(function (f) {
				var td = document.createElement('td');
				var input = document.createElement('input');
				input.type = 'text';
				input.name = p + '[' + f + ']';
				td.appendChild(input);
				tr.appendChild(td);
			});
			var tdDel = document.createElement('td');
			tdDel.innerHTML = '<button type="button" class="button-link button-link-delete" data-cmp-del-svc-cookie>&times;</button>';
			tr.appendChild(tdDel);
			body.appendChild(tr);
			return true;
		}
		var delCk = e.target.closest('[data-cmp-del-svc-cookie]');
		if (delCk) {
			var ckRow = delCk.closest('[data-cmp-svc-cookie-row]');
			if (ckRow) { ckRow.parentNode.removeChild(ckRow); }
			return true;
		}
		return false;
	}

	function onClick(e) {
		if (onServicesClick(e)) { return; }
		var addCat = e.target.closest('[data-cmp-add-cat]');
		if (addCat) {
			var tpl = document.getElementById('cmp-cat-template');
			var wrap = document.querySelector('[data-cmp-cats]');
			var div = document.createElement('div');
			div.innerHTML = tpl.innerHTML.replace(/__i__/g, 'new' + Date.now());
			wrap.appendChild(div.firstElementChild || div);
			reindex(wrap);
			return;
		}
		var delCat = e.target.closest('[data-cmp-del-cat]');
		if (delCat) {
			var row = delCat.closest('[data-cmp-cat-row]');
			var parent = row.parentNode;
			row.parentNode.removeChild(row);
			reindex(parent);
			return;
		}
		var addCk = e.target.closest('[data-cmp-add-cookie]');
		if (addCk) {
			var body = addCk.closest('.cmp-cookies').querySelector('[data-cmp-cookies-body]');
			var tr = body.querySelector('[data-cmp-cookie-row]');
			var newTr;
			if (tr) {
				newTr = tr.cloneNode(true);
				newTr.querySelectorAll('input').forEach(function (i) { i.value = ''; });
			} else {
				return; // no template row to clone; save an empty cookie manually
			}
			body.appendChild(newTr);
			return;
		}
		var delCk = e.target.closest('[data-cmp-del-cookie]');
		if (delCk) {
			var ckRow = delCk.closest('[data-cmp-cookie-row]');
			ckRow.parentNode.removeChild(ckRow);
			return;
		}
	}

	// Settings page: show/hide rows tagged with data-cmp-mode based on the
	// selected display mode. Rows without the attribute are always visible.
	function initSettingsMode() {
		var sel = document.querySelector('select[name="cmp[mode]"]');
		if (!sel) { return; }
		function apply() {
			var mode = sel.value;
			document.querySelectorAll('[data-cmp-mode]').forEach(function (el) {
				el.style.display = (el.getAttribute('data-cmp-mode') === mode) ? '' : 'none';
			});
		}
		sel.addEventListener('change', apply);
		apply();
	}

	document.addEventListener('DOMContentLoaded', function () {
		var page = document.querySelector('.cmp-admin');
		if (page) { page.addEventListener('click', onClick); }
		initSettingsMode();
	});
})();
