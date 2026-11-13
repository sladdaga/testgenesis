"use strict";
(() => {
    var _a;
    document.querySelectorAll('form').forEach((f) => {
        f.addEventListener('submit', (ev) => {
            const c = f.getAttribute('data-confirm');
            if (c && !window.confirm(c)) {
                ev.preventDefault();
                return;
            }
            const b = f.querySelector('[data-once]');
            if (b) {
                b.disabled = true;
                b.textContent = 'Enviando...';
            }
        });
    });
    document.querySelectorAll('button[data-confirm]').forEach((b) => {
        b.addEventListener('click', (ev) => {
            var _a;
            if (!window.confirm((_a = b.getAttribute('data-confirm')) !== null && _a !== void 0 ? _a : ''))
                ev.preventDefault();
        });
    });
    document.querySelectorAll('[data-copy]').forEach((b) => {
        b.addEventListener('click', () => {
            var _a;
            const t = (_a = b.getAttribute('data-copy')) !== null && _a !== void 0 ? _a : '';
            const done = () => {
                const o = b.textContent;
                b.textContent = 'Copiado';
                setTimeout(() => { b.textContent = o; }, 1500);
            };
            if (navigator.clipboard) {
                navigator.clipboard.writeText(t).then(done);
            }
            else {
                const i = document.createElement('input');
                i.value = t;
                document.body.appendChild(i);
                i.select();
                document.execCommand('copy');
                i.remove();
                done();
            }
        });
    });
    const box = document.getElementById('preguntas');
    if (!box)
        return;
    const tq = document.getElementById('tpl-q');
    const to = document.getElementById('tpl-o');
    let nq = parseInt((_a = box.getAttribute('data-next')) !== null && _a !== void 0 ? _a : '', 10) || 100;
    const renum = () => {
        box.querySelectorAll('.qbox').forEach((q, i) => {
            q.querySelector('.qnum').textContent = String(i + 1);
        });
    };
    const html = (tpl, map) => {
        const d = document.createElement('div');
        let h = tpl.innerHTML;
        Object.keys(map).forEach((k) => { h = h.split(k).join(String(map[k])); });
        d.innerHTML = h;
        return d.firstElementChild;
    };
    document.getElementById('add-q').addEventListener('click', () => {
        box.appendChild(html(tq, { __Q__: nq++, __O__: '' }));
        renum();
    });
    box.addEventListener('click', (ev) => {
        var _a, _b;
        const t = ev.target;
        const q = t.closest('.qbox');
        if (!q)
            return;
        if (t.classList.contains('add-o')) {
            const list = q.querySelector('.olist');
            if (list.children.length >= 6) {
                alert('Máximo 6 opciones.');
                return;
            }
            const n = parseInt((_a = q.getAttribute('data-nexto')) !== null && _a !== void 0 ? _a : '', 10) || 100;
            q.setAttribute('data-nexto', String(n + 1));
            list.appendChild(html(to, { __Q__: (_b = q.getAttribute('data-q')) !== null && _b !== void 0 ? _b : '', __O__: n }));
        }
        else if (t.classList.contains('del-o')) {
            if (q.querySelectorAll('.obox').length <= 2) {
                alert('Mínimo 2 opciones.');
                return;
            }
            t.closest('.obox').remove();
        }
        else if (t.classList.contains('del-q')) {
            if (box.querySelectorAll('.qbox').length <= 1) {
                alert('Debe haber al menos una pregunta.');
                return;
            }
            q.remove();
            renum();
        }
    });
})();
