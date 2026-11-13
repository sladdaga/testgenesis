// Fuente TypeScript. Compilar con: npm run build  ->  public/assets/app.js
(() => {
  // Evitar doble envío y confirmaciones en formularios
  document.querySelectorAll<HTMLFormElement>('form').forEach((f) => {
    f.addEventListener('submit', (ev) => {
      const c = f.getAttribute('data-confirm');
      if (c && !window.confirm(c)) { ev.preventDefault(); return; }
      const b = f.querySelector<HTMLButtonElement>('[data-once]');
      if (b) { b.disabled = true; b.textContent = 'Enviando...'; }
    });
  });

  // Confirmaciones en botones
  document.querySelectorAll<HTMLButtonElement>('button[data-confirm]').forEach((b) => {
    b.addEventListener('click', (ev) => {
      if (!window.confirm(b.getAttribute('data-confirm') ?? '')) ev.preventDefault();
    });
  });

  // Copiar enlace
  document.querySelectorAll<HTMLElement>('[data-copy]').forEach((b) => {
    b.addEventListener('click', () => {
      const t = b.getAttribute('data-copy') ?? '';
      const done = (): void => {
        const o = b.textContent;
        b.textContent = 'Copiado';
        setTimeout(() => { b.textContent = o; }, 1500);
      };
      if (navigator.clipboard) {
        navigator.clipboard.writeText(t).then(done);
      } else {
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

  // Editor dinámico de preguntas
  const box = document.getElementById('preguntas');
  if (!box) return;
  const tq = document.getElementById('tpl-q') as HTMLTemplateElement;
  const to = document.getElementById('tpl-o') as HTMLTemplateElement;
  let nq = parseInt(box.getAttribute('data-next') ?? '', 10) || 100;

  const renum = (): void => {
    box.querySelectorAll('.qbox').forEach((q, i) => {
      q.querySelector('.qnum')!.textContent = String(i + 1);
    });
  };

  const html = (tpl: HTMLElement, map: Record<string, string | number>): Element => {
    const d = document.createElement('div');
    let h = tpl.innerHTML;
    Object.keys(map).forEach((k) => { h = h.split(k).join(String(map[k])); });
    d.innerHTML = h;
    return d.firstElementChild!;
  };

  document.getElementById('add-q')!.addEventListener('click', () => {
    box.appendChild(html(tq, { __Q__: nq++, __O__: '' }));
    renum();
  });

  box.addEventListener('click', (ev) => {
    const t = ev.target as HTMLElement;
    const q = t.closest<HTMLElement>('.qbox');
    if (!q) return;
    if (t.classList.contains('add-o')) {
      const list = q.querySelector('.olist')!;
      if (list.children.length >= 6) { alert('Máximo 6 opciones.'); return; }
      const n = parseInt(q.getAttribute('data-nexto') ?? '', 10) || 100;
      q.setAttribute('data-nexto', String(n + 1));
      list.appendChild(html(to, { __Q__: q.getAttribute('data-q') ?? '', __O__: n }));
    } else if (t.classList.contains('del-o')) {
      if (q.querySelectorAll('.obox').length <= 2) { alert('Mínimo 2 opciones.'); return; }
      t.closest('.obox')!.remove();
    } else if (t.classList.contains('del-q')) {
      if (box.querySelectorAll('.qbox').length <= 1) { alert('Debe haber al menos una pregunta.'); return; }
      q.remove();
      renum();
    }
  });
})();
