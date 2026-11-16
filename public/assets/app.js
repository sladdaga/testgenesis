"use strict";
(() => {
    var _a, _b;
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
    const reloj = document.getElementById('reloj');
    const relojV = document.getElementById('reloj-v');
    const qform = document.getElementById('qform');
    if (reloj && relojV && qform) {
        const fin = Date.now() + (parseInt((_a = reloj.getAttribute('data-restante')) !== null && _a !== void 0 ? _a : '0', 10) || 0) * 1000;
        let enviado = false;
        const pintar = () => {
            const seg = Math.max(0, Math.ceil((fin - Date.now()) / 1000));
            const m = Math.floor(seg / 60);
            relojV.textContent = String(m).padStart(2, '0') + ':' + String(seg % 60).padStart(2, '0');
            reloj.classList.toggle('aviso', seg <= 600 && seg > 300);
            reloj.classList.toggle('critico', seg <= 300);
            if (seg <= 0 && !enviado) {
                enviado = true;
                qform.noValidate = true;
                qform.submit();
            }
        };
        pintar();
        window.setInterval(pintar, 1000);
    }
    const box = document.getElementById('preguntas');
    if (!box)
        return;
    const tq = document.getElementById('tpl-q');
    let nq = parseInt((_b = box.getAttribute('data-next')) !== null && _b !== void 0 ? _b : '', 10) || 100;
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
    const aplicarTipo = (q) => {
        const tipo = q.querySelector('select.tipo').value;
        q.querySelectorAll('.blk').forEach((b) => {
            var _a;
            const activo = ((_a = b.getAttribute('data-for')) !== null && _a !== void 0 ? _a : '').split(' ').indexOf(tipo) >= 0;
            b.classList.toggle('hidden', !activo);
            b.querySelectorAll('input, select, button').forEach((c) => { c.disabled = !activo; });
        });
    };
    const syncCols = (q) => {
        const cols = [];
        q.querySelectorAll('.cbox').forEach((c) => {
            var _a;
            const t = c.querySelector('.ctxt').value.trim();
            if (t !== '')
                cols.push([(_a = c.getAttribute('data-k')) !== null && _a !== void 0 ? _a : '', t]);
        });
        q.querySelectorAll('select.fcol').forEach((sel) => {
            const actual = sel.value;
            sel.innerHTML = '';
            const o0 = document.createElement('option');
            o0.value = '';
            o0.textContent = 'Correcta…';
            sel.appendChild(o0);
            cols.forEach(([k, t]) => {
                const o = document.createElement('option');
                o.value = k;
                o.textContent = t;
                sel.appendChild(o);
            });
            sel.value = actual;
        });
    };
    box.querySelectorAll('.qbox').forEach(aplicarTipo);
    document.getElementById('add-q').addEventListener('click', () => {
        const q = html(tq, { __Q__: nq++, __O__: '' });
        box.appendChild(q);
        aplicarTipo(q);
        renum();
    });
    box.addEventListener('change', (ev) => {
        const t = ev.target;
        const q = t.closest('.qbox');
        if (q && t.classList.contains('tipo'))
            aplicarTipo(q);
    });
    box.addEventListener('input', (ev) => {
        const t = ev.target;
        const q = t.closest('.qbox');
        if (q && t.classList.contains('ctxt'))
            syncCols(q);
    });
    box.addEventListener('click', (ev) => {
        var _a, _b, _c, _d, _e, _f;
        const t = ev.target;
        const q = t.closest('.qbox');
        if (!q)
            return;
        if (t.classList.contains('add-o')) {
            const list = q.querySelector((_a = t.getAttribute('data-lista')) !== null && _a !== void 0 ? _a : '.olist');
            const max = parseInt((_b = list.getAttribute('data-max')) !== null && _b !== void 0 ? _b : '6', 10);
            if (list.children.length >= max) {
                alert('Máximo ' + max + '.');
                return;
            }
            const n = parseInt((_c = q.getAttribute('data-nexto')) !== null && _c !== void 0 ? _c : '', 10) || 100;
            q.setAttribute('data-nexto', String(n + 1));
            const tpl = document.getElementById((_d = list.getAttribute('data-tpl')) !== null && _d !== void 0 ? _d : 'tpl-o');
            list.appendChild(html(tpl, { __Q__: (_e = q.getAttribute('data-q')) !== null && _e !== void 0 ? _e : '', __O__: n }));
            syncCols(q);
        }
        else if (t.classList.contains('del-o')) {
            const list = t.closest('.olist, .mlist, .clist, .flist');
            const min = parseInt((_f = list.getAttribute('data-min')) !== null && _f !== void 0 ? _f : '2', 10);
            if (list.children.length <= min) {
                alert('Mínimo ' + min + '.');
                return;
            }
            t.closest('.obox').remove();
            syncCols(q);
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
