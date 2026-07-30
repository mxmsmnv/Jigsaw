/* SSL Manager admin scripts */
(function () {
    'use strict';

    // ---- Table column sorting -------------------------------------------
    function initSort() {
        var table = document.getElementById('ssl-table');
        if (!table || table.dataset.sortReady) return;
        table.dataset.sortReady = '1';

        var headers = table.querySelectorAll('th.ssl-sortable');

        function cellValue(row, i) {
            var td = row.children[i];
            var d = td.getAttribute('data-sort');
            if (d !== null) {
                var n = parseFloat(d);
                return isNaN(n) ? d.toLowerCase() : n;
            }
            return td.textContent.trim().toLowerCase();
        }

        function sortBy(col, asc) {
            var tbody = table.tBodies[0];
            var rows = Array.prototype.slice.call(tbody.rows);
            rows.sort(function (a, b) {
                var x = cellValue(a, col), y = cellValue(b, col);
                if (x < y) return asc ? -1 : 1;
                if (x > y) return asc ? 1 : -1;
                return 0;
            });
            rows.forEach(function (r) { tbody.appendChild(r); });
        }

        headers.forEach(function (th) {
            th.addEventListener('click', function () {
                var col = Array.prototype.indexOf.call(th.parentNode.children, th);
                var asc = th.getAttribute('data-dir') !== 'asc';
                sortBy(col, asc);
                headers.forEach(function (h) {
                    h.removeAttribute('data-dir');
                    var a = h.querySelector('.ssl-arrow');
                    if (a) a.textContent = '';
                });
                th.setAttribute('data-dir', asc ? 'asc' : 'desc');
                var arrow = th.querySelector('.ssl-arrow');
                if (arrow) arrow.textContent = asc ? ' ▲' : ' ▼';
            });
        });
    }

    if (document.readyState !== 'loading') initSort();
    else document.addEventListener('DOMContentLoaded', initSort);

    // ---- PEM copy / download (global, called from onclick) --------------
    window.sslDownload = function (id, name) {
        var t = document.getElementById(id);
        if (!t) return;
        var b = new Blob([t.value], { type: 'application/x-pem-file' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(b);
        a.download = name;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
    };

    // ---- Expand the collapsed "Add multiple" fieldset ------------------
    window.sslToggleBulk = function () {
        var w = document.getElementById('wrap_Inputfield_bulkadd')
            || document.querySelector('.InputfieldFieldset.InputfieldStateCollapsed');
        if (!w) return;
        if (w.classList.contains('InputfieldStateCollapsed')) {
            var toggle = w.querySelector('.InputfieldStateToggle');
            if (toggle) toggle.click();
            else w.classList.remove('InputfieldStateCollapsed');
        }
        w.scrollIntoView({ behavior: 'smooth', block: 'center' });
    };

    window.sslCopy = function (id, btn) {
        var t = document.getElementById(id);
        if (!t) return;
        var done = function () {
            var o = btn.innerHTML;
            btn.innerHTML = '<i class="fa fa-check"></i> Copied';
            setTimeout(function () { btn.innerHTML = o; }, 1500);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(t.value).then(done);
        } else {
            t.select();
            document.execCommand('copy');
            done();
        }
    };
})();
