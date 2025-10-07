/* COSMY: Tag archive expander (safe, iOS-friendly) */
(function(){
  'use strict';

  // простенький debounce
  var debounce = function(fn, wait){ var t; return function(){ clearTimeout(t); t = setTimeout(fn, wait); }; };

  function setup(){
    // работаем только на страницах меток
    if (!document.body || !document.body.classList.contains('tag')) return;

    var root = document.querySelector('.archive-description');
    if (!root) return;

    // защита от повторной инициализации
    if (root.dataset.cosmyInit === '1') return;
    root.dataset.cosmyInit = '1';

    // ищем/создаём ВНУТРЕННИЙ контейнер под сворачивание
    var box = root.querySelector('.archive-description__inner');
    if (!box) {
      box = document.createElement('div');
      box.className = 'archive-description__inner';
      // переносим всё текущее содержимое .archive-description внутрь .archive-description__inner
      while (root.firstChild) box.appendChild(root.firstChild);
      root.appendChild(box);
    }

    // ищем/создаём кнопку и вставляем её СРАЗУ ПОСЛЕ box (до .entry-tags, если он есть)
    var toggle = root.querySelector('.cosmy-archive-toggle');
    if (!toggle) {
      toggle = document.createElement('button');
      toggle.type = 'button';
      toggle.className = 'cosmy-archive-toggle';
      toggle.setAttribute('aria-expanded','false');
      toggle.textContent = 'Показать полностью';
      box.insertAdjacentElement('afterend', toggle);
    }

    // пересчёт переполнения — НИКОГДА НЕ СВОРАЧИВАЕМ автоматически, если пользователь раскрыл
    function recalc(){
      if (box.classList.contains('is-expanded')) {
        // держим кнопку активной
        toggle.classList.add('is-visible');
        toggle.setAttribute('aria-expanded','true');
        toggle.textContent = 'Свернуть';
        return;
      }
      var hasOverflow = (box.scrollHeight - box.clientHeight) > 2;
      toggle.classList.toggle('is-visible', hasOverflow);
      if (!hasOverflow) {
        toggle.setAttribute('aria-expanded','false');
        toggle.textContent = 'Показать полностью';
      }
    }

    // переключатель
    toggle.addEventListener('click', function(){
      var expanded = box.classList.toggle('is-expanded');
      // для совместимости: дублируем класс на внешнем контейнере (если где-то остался старый CSS)
      root.classList.toggle('is-expanded', expanded);

      toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
      toggle.textContent = expanded ? 'Свернуть' : 'Показать полностью';

      if (!expanded) {
        try { box.scrollIntoView({ behavior:'smooth', block:'start' }); }
        catch(_) { box.scrollIntoView(true); }
      }
    });

    // первичный расчёт
    recalc();

    // безопасные пересчёты (iOS триггерит resize при скролле/адресной строке)
    var safeRecalc = debounce(recalc, 150);
    window.addEventListener('resize', safeRecalc, { passive:true });
    window.addEventListener('orientationchange', recalc, { passive:true });
    window.addEventListener('load', recalc, { passive:true });

    // если шрифты/контент подгрузятся позже
    if (document.fonts && document.fonts.ready) {
      document.fonts.ready.then(recalc).catch(function(){});
    }

    // следим за изменениями внутри описания (картинки/латенси-контент)
    var mo;
    try {
      mo = new MutationObserver(debounce(recalc, 50));
      mo.observe(box, { childList:true, subtree:true, characterData:true });
    } catch(_){}
  }

  if (document.readyState !== 'loading') setup();
  else document.addEventListener('DOMContentLoaded', setup, { once:true });

})();