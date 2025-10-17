(function(){
  'use strict';

  var debounce = function(fn, wait){ var t; return function(){ clearTimeout(t); t = setTimeout(fn, wait); }; };

  function setup(){
    if (!document.body.classList.contains('tag')) return;
    var box = document.querySelector('.archive-description');
    if (!box) return;
    if (box.dataset.cosmyInit === '1') return;
    box.dataset.cosmyInit = '1';

    var toggle = document.querySelector('.cosmy-archive-toggle');
    if (!toggle) {
      toggle = document.createElement('button');
      toggle.className = 'cosmy-archive-toggle';
      toggle.type = 'button';
      toggle.setAttribute('aria-expanded','false');
      toggle.textContent = 'Показать полностью';
      box.insertAdjacentElement('afterend', toggle);
    }

    // ВАЖНО: больше не сворачиваем автоматически
    function recalc(){
      if (box.classList.contains('is-expanded')) {
        // Просто держим кнопку видимой в режиме "Свернуть"
        toggle.classList.add('is-visible');
        toggle.setAttribute('aria-expanded','true');
        toggle.textContent = 'Свернуть';
        return;
      }
      // Считаем переполнение ТОЛЬКО в свернутом состоянии
      var hasOverflow = (box.scrollHeight - box.clientHeight) > 2;
      toggle.classList.toggle('is-visible', hasOverflow);
      if (!hasOverflow) {
        toggle.setAttribute('aria-expanded','false');
        toggle.textContent = 'Показать полностью';
      }
    }

    toggle.addEventListener('click', function(){
      var expanded = box.classList.toggle('is-expanded');
      toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
      toggle.textContent = expanded ? 'Свернуть' : 'Показать полностью';
      if (!expanded) {
        try { box.scrollIntoView({ behavior:'smooth', block:'start' }); }
        catch(_) { box.scrollIntoView(true); }
      }
    });

    // первичный расчёт и безопасные пересчёты (iOS триггерит resize на скролле)
    recalc();
    var safeRecalc = debounce(recalc, 150);
    window.addEventListener('resize', safeRecalc, { passive:true });
    window.addEventListener('orientationchange', recalc, { passive:true });
    if (document.fonts && document.fonts.ready) { document.fonts.ready.then(recalc).catch(function(){}); }
    window.addEventListener('load', recalc, { passive:true });
  }

  if (document.readyState !== 'loading') setup();
  else document.addEventListener('DOMContentLoaded', setup, { once:true });

})();