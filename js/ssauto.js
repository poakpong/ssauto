/**
 * @file
 * Smart Search Autocomplete — overlay UI + results-page autocomplete.
 *
 * Depends on: core/drupal, core/jquery, core/drupalSettings, core/once.
 */

(function ($, Drupal, drupalSettings, once) {
  'use strict';

  // ===========================================================================
  // Helper: shared autocomplete fetch logic
  // ===========================================================================

  function makeFetcher(autocompleteUrl) {
    var xhr = null;
    return function fetch(keyword, onSuccess, onEmpty) {
      if (xhr) { xhr.abort(); xhr = null; }
      if (!keyword || keyword.length < 2) { onEmpty(); return; }
      xhr = $.ajax({
        url: autocompleteUrl,
        data: { q: keyword },
        dataType: 'json',
        success: function (data) { onSuccess(data); },
        error: function (jqXHR) { if (jqXHR.statusText !== 'abort') { onEmpty(); } },
      });
    };
  }

  function highlightKeyword(text, keyword) {
    if (!keyword) { return Drupal.checkPlain(text); }
    var escaped = keyword.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    return Drupal.checkPlain(text).replace(new RegExp('(' + escaped + ')', 'gi'), '<mark>$1</mark>');
  }

  // ===========================================================================
  // Behavior 1: Overlay block
  // ===========================================================================

  Drupal.behaviors.ssautoOverlay = {
    attach: function attach(context, settings) {
      var autocompleteUrl = (settings.ssauto && settings.ssauto.autocompleteUrl) || '/api/ssauto/autocomplete';
      var searchPageUrl   = (settings.ssauto && settings.ssauto.searchPageUrl)   || '/smart-search';

      once('ssauto-overlay', '.ssauto-wrapper', context).forEach(function (wrapper) {
        var $wrapper     = $(wrapper);
        var $trigger     = $wrapper.find('.ssauto-trigger');
        var $overlay     = $wrapper.find('.ssauto-overlay');
        var $backdrop    = $wrapper.find('.ssauto-backdrop');
        var $closeBtn    = $wrapper.find('.ssauto-close');
        var $input       = $wrapper.find('.ssauto-input');
        var $submitBtn   = $wrapper.find('.ssauto-submit');
        var $suggestions = $wrapper.find('.ssauto-suggestions');

        // Teleport overlay + backdrop to <body> so they escape any parent
        // CSS transform / overflow that would clip position:fixed children.
        $('body').append($backdrop).append($overlay);

        var isOpen       = false;
        var activeIndex  = -1;
        var currentItems = [];
        var debounceTimer = null;
        var fetch        = makeFetcher(autocompleteUrl);

        // Keep overlay top aligned with Drupal toolbar (displace API).
        function updateOverlayTop() {
          var offset = parseInt($('body').css('padding-top') || 0, 10);
          $overlay.css('top', offset > 0 ? offset + 'px' : '');
        }
        updateOverlayTop();
        $(document).on('drupalViewportOffsetChange.ssauto', updateOverlayTop);

        // -----------------------------------------------------------------------
        // Open / close
        // -----------------------------------------------------------------------
        function openOverlay() {
          updateOverlayTop();
          isOpen = true;
          $overlay.removeAttr('hidden').addClass('is-open');
          $trigger.addClass('is-active').attr('aria-expanded', 'true');
          $('body').addClass('ssauto-active');
          $input.trigger('focus');
        }

        function closeOverlay() {
          isOpen = false;
          $overlay.removeClass('is-open');
          $trigger.removeClass('is-active').attr('aria-expanded', 'false');
          $('body').removeClass('ssauto-active');
          $input.val('');
          clearSuggestions();
          setTimeout(function () { if (!isOpen) { $overlay.attr('hidden', ''); } }, 560);
        }

        // -----------------------------------------------------------------------
        // Suggestions
        // -----------------------------------------------------------------------
        function clearSuggestions() {
          $suggestions.empty();
          activeIndex  = -1;
          currentItems = [];
          $input.attr('aria-activedescendant', '');
        }

        function renderSuggestions(items, keyword) {
          clearSuggestions();
          if (!items.length) { return; }
          currentItems = items;
          var fragment = document.createDocumentFragment();
          items.forEach(function (item, idx) {
            var li = document.createElement('li');
            li.className = 'ssauto-suggestion';
            li.setAttribute('role', 'option');
            li.setAttribute('id', 'ssauto-option-' + idx);
            li.setAttribute('aria-selected', 'false');
            li.innerHTML =
              '<span class="ssauto-suggestion__text">' + highlightKeyword(item.label, keyword) + '</span>' +
              '<svg class="ssauto-suggestion__arrow" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
                '<line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>' +
              '</svg>';
            li.addEventListener('click', function () { window.location.href = item.url; });
            fragment.appendChild(li);
          });
          $suggestions[0].appendChild(fragment);
        }

        function showLoading() {
          $suggestions.html(
            '<li class="ssauto-loading" aria-live="polite">' +
              '<span class="ssauto-loading__dot"></span>' +
              '<span class="ssauto-loading__dot"></span>' +
              '<span class="ssauto-loading__dot"></span>' +
            '</li>'
          );
        }

        // -----------------------------------------------------------------------
        // Keyboard navigation
        // -----------------------------------------------------------------------
        function setActiveIndex(idx) {
          var $items = $suggestions.find('.ssauto-suggestion');
          $items.removeClass('is-active').attr('aria-selected', 'false');
          if (idx < 0 || idx >= $items.length) {
            activeIndex = -1;
            $input.attr('aria-activedescendant', '');
            return;
          }
          activeIndex = idx;
          $items.eq(idx).addClass('is-active').attr('aria-selected', 'true');
          $input.attr('aria-activedescendant', 'ssauto-option-' + idx);
          if (currentItems[idx]) { $input.val(currentItems[idx].value); }
        }

        // -----------------------------------------------------------------------
        // Events
        // -----------------------------------------------------------------------
        $trigger.on('click', function (e) { e.stopPropagation(); isOpen ? closeOverlay() : openOverlay(); });
        $closeBtn.on('click', closeOverlay);
        $backdrop.on('click', closeOverlay);

        $(document).on('keydown.ssauto', function (e) { if (e.key === 'Escape' && isOpen) { closeOverlay(); } });

        $input.on('input', function () {
          clearTimeout(debounceTimer);
          var keyword = $input.val().trim();
          if (!keyword) { clearSuggestions(); return; }
          debounceTimer = setTimeout(function () {
            showLoading();
            fetch(keyword, function (data) { renderSuggestions(data, keyword); }, clearSuggestions);
          }, 250);
        });

        $input.on('keydown', function (e) {
          var $items = $suggestions.find('.ssauto-suggestion');
          var count  = $items.length;
          switch (e.key) {
            case 'ArrowDown': e.preventDefault(); setActiveIndex(activeIndex < count - 1 ? activeIndex + 1 : 0); break;
            case 'ArrowUp':   e.preventDefault(); setActiveIndex(activeIndex > 0 ? activeIndex - 1 : count - 1); break;
            case 'Enter':
              e.preventDefault();
              if (activeIndex >= 0 && currentItems[activeIndex]) {
                window.location.href = currentItems[activeIndex].url;
              } else {
                var q = $input.val().trim();
                if (q) { window.location.href = searchPageUrl + '?q=' + encodeURIComponent(q); }
              }
              break;
            case 'Escape': closeOverlay(); break;
          }
        });

        $submitBtn.on('click', function () {
          var q = $input.val().trim();
          if (q) { window.location.href = searchPageUrl + '?q=' + encodeURIComponent(q); }
        });

        // -----------------------------------------------------------------------
        // -----------------------------------------------------------------------
        // Theme-nav integration — single click to open overlay.
        //
        // Problem: many themes place this block inside a collapsed search drawer
        // (display:none / height:0). The user's first click hits the theme's own
        // toggle icon (outside our block), which expands the drawer. Only then
        // can they reach our trigger — resulting in 2 clicks to open search.
        //
        // Solution (requires no theme class names):
        //  1. Walk up from .ssauto-wrapper to find the nearest hidden ancestor.
        //  2. Hide our trigger (it is trapped inside the collapsed container).
        //  3. Intercept ANY click on the *visible* sibling area of that ancestor
        //     (i.e. the theme's toggle icon) and open our overlay directly — so
        //     the very first click on the theme's icon opens the ssauto overlay.
        // -----------------------------------------------------------------------
        (function () {
          // Detect hidden/collapsed elements regardless of which CSS technique the
          // theme uses: display:none, visibility:hidden, height/offsetHeight=0,
          // opacity:0, OR pointer-events:none (common in modern slide-in drawers).
          function isCollapsed(el) {
            if (!el || el === document.documentElement) { return false; }
            var s = window.getComputedStyle(el);
            return s.display        === 'none'
                || s.visibility     === 'hidden'
                || el.offsetHeight  === 0
                || parseFloat(s.opacity) === 0
                || s.pointerEvents  === 'none';
          }

          // Walk up from the wrapper to find the nearest hidden ancestor.
          var hiddenAncestor = null;
          var node = wrapper.parentNode;
          while (node && node !== document.body) {
            if (isCollapsed(node)) { hiddenAncestor = node; break; }
            node = node.parentNode;
          }

          if (!hiddenAncestor) { return; } // Wrapper is already visible — trigger works normally.

          // Our trigger is unreachable inside the collapsed container; hide it.
          // This also prevents the stray "×" icon that appears when openOverlay()
          // adds is-active to the still-visible trigger button.
          $trigger.hide();

          // Intercept clicks on the VISIBLE part of the container using the
          // CAPTURE phase so we fire before the theme's own handler — even if the
          // theme calls stopPropagation() in its bubble-phase handler.
          hiddenAncestor.parentNode.addEventListener('click', function (e) {
            // Ignore clicks that come from inside the collapsed container.
            if ($(e.target).closest(hiddenAncestor).length) { return; }
            // Ignore our own overlay / backdrop (teleported to body level).
            if ($(e.target).closest('.ssauto-overlay, .ssauto-backdrop').length) { return; }
            isOpen ? closeOverlay() : openOverlay();
          }, true /* capture: fires before theme's stopPropagation */);
        }());

      });
    },
  };

  // ===========================================================================
  // Behavior 2: Autocomplete on /smart-search results page input
  // ===========================================================================

  Drupal.behaviors.ssautoResultsAutocomplete = {
    attach: function attach(context, settings) {
      var autocompleteUrl = (settings.ssauto && settings.ssauto.autocompleteUrl) || '/api/ssauto/autocomplete';

      once('ssauto-results-ac', '.ssauto-results-form__input', context).forEach(function (input) {
        var $input    = $(input);
        var $row      = $input.closest('.ssauto-results-form__row');
        // Append dropdown inside the row so it can use position:absolute top:100%.
        var $dropdown = $('<ul class="ssauto-results-ac-dropdown" role="listbox"></ul>');
        $row.css('position', 'relative').append($dropdown);
        var debounceTimer = null;
        var fetch     = makeFetcher(autocompleteUrl);

        function clearDropdown() { $dropdown.empty().hide(); }

        function renderDropdown(items) {
          clearDropdown();
          if (!items.length) { return; }
          items.forEach(function (item) {
            var $li = $('<li class="ssauto-results-ac-item" role="option" tabindex="-1"></li>').text(item.label);
            $li.on('mousedown', function (e) {
              e.preventDefault(); // prevent input blur before click
              window.location.href = item.url;
            });
            $dropdown.append($li);
          });
          $dropdown.show();
        }

        $input.on('input', function () {
          clearTimeout(debounceTimer);
          var keyword = $input.val().trim();
          if (!keyword) { clearDropdown(); return; }
          debounceTimer = setTimeout(function () {
            fetch(keyword, renderDropdown, clearDropdown);
          }, 250);
        });

        $input.on('blur', function () {
          setTimeout(clearDropdown, 150);
        });

        $input.on('keydown', function (e) {
          if (e.key === 'Escape') { clearDropdown(); }
        });
      });
    },
  };

}(jQuery, Drupal, drupalSettings, once));
