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

  // Mostrar / ocultar contraseña
  document.querySelectorAll<HTMLButtonElement>('[data-toggle-pass]').forEach((b) => {
    const inp = b.parentElement?.querySelector<HTMLInputElement>('input');
    if (!inp) return;
    b.hidden = false;
    b.addEventListener('click', () => {
      const ver = inp.type === 'password';
      inp.type = ver ? 'text' : 'password';
      b.textContent = ver ? 'Ocultar' : 'Mostrar';
      b.setAttribute('aria-pressed', ver ? 'true' : 'false');
      inp.focus();
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

  // Temporizador del examen (el servidor valida el límite real)
  const reloj = document.getElementById('reloj');
  const relojV = document.getElementById('reloj-v');
  const qform = document.getElementById('qform') as HTMLFormElement | null;
  if (reloj && relojV && qform) {
    const fin = Date.now() + (parseInt(reloj.getAttribute('data-restante') ?? '0', 10) || 0) * 1000;
    let enviado = false;
    const pintar = (): void => {
      const seg = Math.max(0, Math.ceil((fin - Date.now()) / 1000));
      const m = Math.floor(seg / 60);
      relojV.textContent = String(m).padStart(2, '0') + ':' + String(seg % 60).padStart(2, '0');
      reloj.classList.toggle('aviso', seg <= 600 && seg > 300);
      reloj.classList.toggle('critico', seg <= 300);
      if (seg <= 0 && !enviado) {
        enviado = true;
        qform.noValidate = true; // enviar lo que haya, aunque falten respuestas
        qform.submit();
      }
    };
    pintar();
    window.setInterval(pintar, 1000);
  }

  // Editor dinámico de preguntas
  const box = document.getElementById('preguntas');
  if (!box) return;
  const tq = document.getElementById('tpl-q') as HTMLTemplateElement;
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

  // Muestra solo el bloque del tipo elegido; los campos de los otros bloques se deshabilitan (no se envían)
  const aplicarTipo = (q: HTMLElement): void => {
    const tipo = q.querySelector<HTMLSelectElement>('select.tipo')!.value;
    q.querySelectorAll<HTMLElement>('.blk').forEach((b) => {
      const activo = (b.getAttribute('data-for') ?? '').split(' ').indexOf(tipo) >= 0;
      b.classList.toggle('hidden', !activo);
      b.querySelectorAll<HTMLInputElement | HTMLSelectElement | HTMLButtonElement>('input, select, button').forEach((c) => { c.disabled = !activo; });
    });
  };

  // Regenera las opciones de "columna correcta" de cada fila según las columnas con texto
  const syncCols = (q: HTMLElement): void => {
    const cols: Array<[string, string]> = [];
    q.querySelectorAll<HTMLElement>('.cbox').forEach((c) => {
      const t = c.querySelector<HTMLInputElement>('.ctxt')!.value.trim();
      if (t !== '') cols.push([c.getAttribute('data-k') ?? '', t]);
    });
    q.querySelectorAll<HTMLSelectElement>('select.fcol').forEach((sel) => {
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

  box.querySelectorAll<HTMLElement>('.qbox').forEach(aplicarTipo);

  document.getElementById('add-q')!.addEventListener('click', () => {
    const q = html(tq, { __Q__: nq++, __O__: '' }) as HTMLElement;
    box.appendChild(q);
    aplicarTipo(q);
    renum();
  });

  box.addEventListener('change', (ev) => {
    const t = ev.target as HTMLElement;
    const q = t.closest<HTMLElement>('.qbox');
    if (q && t.classList.contains('tipo')) aplicarTipo(q);
  });

  box.addEventListener('input', (ev) => {
    const t = ev.target as HTMLElement;
    const q = t.closest<HTMLElement>('.qbox');
    if (q && t.classList.contains('ctxt')) syncCols(q);
  });

  box.addEventListener('click', (ev) => {
    const t = ev.target as HTMLElement;
    const q = t.closest<HTMLElement>('.qbox');
    if (!q) return;
    if (t.classList.contains('add-o')) {
      const list = q.querySelector<HTMLElement>(t.getAttribute('data-lista') ?? '.olist')!;
      const max = parseInt(list.getAttribute('data-max') ?? '6', 10);
      if (list.children.length >= max) { alert('Máximo ' + max + '.'); return; }
      const n = parseInt(q.getAttribute('data-nexto') ?? '', 10) || 100;
      q.setAttribute('data-nexto', String(n + 1));
      const tpl = document.getElementById(list.getAttribute('data-tpl') ?? 'tpl-o') as HTMLTemplateElement;
      list.appendChild(html(tpl, { __Q__: q.getAttribute('data-q') ?? '', __O__: n }));
      syncCols(q);
    } else if (t.classList.contains('del-o')) {
      const list = t.closest<HTMLElement>('.olist, .mlist, .clist, .flist')!;
      const min = parseInt(list.getAttribute('data-min') ?? '2', 10);
      if (list.children.length <= min) { alert('Mínimo ' + min + '.'); return; }
      t.closest('.obox')!.remove();
      syncCols(q);
    } else if (t.classList.contains('del-q')) {
      if (box.querySelectorAll('.qbox').length <= 1) { alert('Debe haber al menos una pregunta.'); return; }
      q.remove();
      renum();
    }
  });
})();
