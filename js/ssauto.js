/**
 * @file
 * Smart Search Autocomplete — overlay UI with keyboard navigation.
 *
 * Depends on: core/drupal, core/jquery, core/drupalSettings.
 */

(function ($, Drupal, drupalSettings) {
  'use strict';

  Drupal.behaviors.ssautoOverlay = {
    attach: function attach(context, settings) {
      // Guard: only attach once per page (not per AJAX response).
      const wrapper = once('ssauto-overlay', '.ssauto-wrapper', context);
      if (!wrapper.length) {
        return;
      }

      // -----------------------------------------------------------------------
      // DOM references
      // -----------------------------------------------------------------------
      const $wrapper    = $(wrapper);
      const $trigger    = $wrapper.find('.ssauto-trigger');
      const $overlay    = $wrapper.find('.ssauto-overlay');
      const $backdrop   = $wrapper.find('.ssauto-backdrop');
      const $closeBtn   = $wrapper.find('.ssauto-close');
      const $input      = $wrapper.find('.ssauto-input');
      const $submitBtn  = $wrapper.find('.ssauto-submit');
      const $suggestions = $wrapper.find('.ssauto-suggestions');

      const autocompleteUrl = (settings.ssauto && settings.ssauto.autocompleteUrl) || '/api/ssauto/autocomplete';
      const searchPageUrl   = (settings.ssauto && settings.ssauto.searchPageUrl)   || '/smart-search';

      // -----------------------------------------------------------------------
      // State
      // -----------------------------------------------------------------------
      let isOpen      = false;
      let activeIndex = -1;
      let currentItems = [];
      let debounceTimer = null;
      let xhr = null;

      // -----------------------------------------------------------------------
      // Open / close helpers
      // -----------------------------------------------------------------------
      function openOverlay() {
        isOpen = true;
        $overlay.removeAttr('hidden').addClass('is-open');
        $trigger.addClass('is-active').attr('aria-expanded', 'true');
        $('body').addClass('ssauto-active');

        // Focus the input after the slide-in transition starts.
        setTimeout(function () {
          $input.trigger('focus');
        }, 350);
      }

      function closeOverlay() {
        isOpen = false;
        $overlay.removeClass('is-open');
        $trigger.removeClass('is-active').attr('aria-expanded', 'false');
        $('body').removeClass('ssauto-active');
        $input.val('');
        clearSuggestions();

        // Re-hide after transition so keyboard and screen readers cannot reach it.
        setTimeout(function () {
          if (!isOpen) {
            $overlay.attr('hidden', '');
          }
        }, 560);
      }

      // -----------------------------------------------------------------------
      // Suggestion rendering
      // -----------------------------------------------------------------------
      function clearSuggestions() {
        $suggestions.empty();
        activeIndex = -1;
        currentItems = [];
        $input.attr('aria-activedescendant', '');
      }

      function highlightKeyword(text, keyword) {
        if (!keyword) return Drupal.checkPlain(text);
        const escaped = keyword.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const regex   = new RegExp('(' + escaped + ')', 'gi');
        return Drupal.checkPlain(text).replace(regex, '<mark>$1</mark>');
      }

      function renderSuggestions(items, keyword) {
        clearSuggestions();
        if (!items.length) {
          return;
        }
        currentItems = items;

        const fragment = document.createDocumentFragment();
        items.forEach(function (item, idx) {
          const li = document.createElement('li');
          li.className = 'ssauto-suggestion';
          li.setAttribute('role', 'option');
          li.setAttribute('id', 'ssauto-option-' + idx);
          li.setAttribute('aria-selected', 'false');

          li.innerHTML =
            '<span class="ssauto-suggestion__text">' +
              highlightKeyword(item.label, keyword) +
            '</span>' +
            '<svg class="ssauto-suggestion__arrow" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
              '<line x1="5" y1="12" x2="19" y2="12"/>' +
              '<polyline points="12 5 19 12 12 19"/>' +
            '</svg>';

          li.addEventListener('click', function () {
            window.location.href = item.url;
          });

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
      // Keyboard navigation within suggestions
      // -----------------------------------------------------------------------
      function setActiveIndex(idx) {
        const $items = $suggestions.find('.ssauto-suggestion');
        $items.removeClass('is-active').attr('aria-selected', 'false');

        if (idx < 0 || idx >= $items.length) {
          activeIndex = -1;
          $input.attr('aria-activedescendant', '');
          return;
        }

        activeIndex = idx;
        const $active = $items.eq(idx);
        $active.addClass('is-active').attr('aria-selected', 'true');
        $input.attr('aria-activedescendant', 'ssauto-option-' + idx);

        // Update input value to match active suggestion.
        if (currentItems[idx]) {
          $input.val(currentItems[idx].value);
        }
      }

      // -----------------------------------------------------------------------
      // Autocomplete fetch (debounced)
      // -----------------------------------------------------------------------
      function fetchSuggestions(keyword) {
        if (xhr) {
          xhr.abort();
          xhr = null;
        }
        if (!keyword || keyword.length < 2) {
          clearSuggestions();
          return;
        }

        showLoading();

        xhr = $.ajax({
          url: autocompleteUrl,
          data: { q: keyword },
          dataType: 'json',
          success: function (data) {
            renderSuggestions(data, keyword);
          },
          error: function (jqXHR) {
            if (jqXHR.statusText !== 'abort') {
              clearSuggestions();
            }
          },
        });
      }

      // -----------------------------------------------------------------------
      // Event: trigger button click
      // -----------------------------------------------------------------------
      $trigger.on('click', function () {
        if (isOpen) {
          closeOverlay();
        } else {
          openOverlay();
        }
      });

      // -----------------------------------------------------------------------
      // Event: close button inside overlay
      // -----------------------------------------------------------------------
      $closeBtn.on('click', closeOverlay);

      // -----------------------------------------------------------------------
      // Event: backdrop click
      // -----------------------------------------------------------------------
      $backdrop.on('click', closeOverlay);

      // -----------------------------------------------------------------------
      // Event: ESC key (document level)
      // -----------------------------------------------------------------------
      $(document).on('keydown.ssauto', function (e) {
        if (e.key === 'Escape' && isOpen) {
          closeOverlay();
        }
      });

      // -----------------------------------------------------------------------
      // Event: input — debounced autocomplete
      // -----------------------------------------------------------------------
      $input.on('input', function () {
        clearTimeout(debounceTimer);
        const keyword = $input.val().trim();
        if (!keyword) {
          clearSuggestions();
          return;
        }
        debounceTimer = setTimeout(function () {
          fetchSuggestions(keyword);
        }, 250);
      });

      // -----------------------------------------------------------------------
      // Event: keyboard navigation (ArrowUp / ArrowDown / Enter)
      // -----------------------------------------------------------------------
      $input.on('keydown', function (e) {
        const $items = $suggestions.find('.ssauto-suggestion');
        const count  = $items.length;

        switch (e.key) {
          case 'ArrowDown':
            e.preventDefault();
            setActiveIndex(activeIndex < count - 1 ? activeIndex + 1 : 0);
            break;

          case 'ArrowUp':
            e.preventDefault();
            setActiveIndex(activeIndex > 0 ? activeIndex - 1 : count - 1);
            break;

          case 'Enter':
            e.preventDefault();
            if (activeIndex >= 0 && currentItems[activeIndex]) {
              window.location.href = currentItems[activeIndex].url;
            } else {
              var q = $input.val().trim();
              if (q) {
                window.location.href = searchPageUrl + '?q=' + encodeURIComponent(q);
              }
            }
            break;

          case 'Escape':
            closeOverlay();
            break;
        }
      });

      // -----------------------------------------------------------------------
      // Event: submit button click
      // -----------------------------------------------------------------------
      $submitBtn.on('click', function () {
        var q = $input.val().trim();
        if (q) {
          window.location.href = searchPageUrl + '?q=' + encodeURIComponent(q);
        }
      });
    },
  };

}(jQuery, Drupal, drupalSettings));
