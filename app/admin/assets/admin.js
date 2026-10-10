// Vigilo admin
(function () {
  'use strict';

  var toggle = document.getElementById('theme-toggle');
  if (toggle) {
    toggle.addEventListener('click', function () {
      var html = document.documentElement;
      var next = html.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
      html.setAttribute('data-bs-theme', next);
      try { localStorage.setItem('vigilo-admin-theme', next); } catch (e) {}
    });
  }

  // Links and buttons that need a confirmation: data-confirm="message"
  document.addEventListener('click', function (event) {
    var el = event.target.closest('[data-confirm]');
    if (el && !window.confirm(el.getAttribute('data-confirm'))) {
      event.preventDefault();
      event.stopPropagation();
    }
  }, true);

  // Photos: open in the photo window instead of a new tab (<a href="photo.php..." data-photo>)
  var photoModal = document.getElementById('photoModal');
  document.addEventListener('click', function (event) {
    var link = event.target.closest('a[data-photo]');
    if (!link || !photoModal || !window.bootstrap || event.ctrlKey || event.metaKey || event.shiftKey) {
      return;
    }
    event.preventDefault();
    var img = document.getElementById('photoModalImage');
    img.src = link.getAttribute('data-photo') || link.href;
    img.alt = link.getAttribute('data-photo-title') || 'Photo';
    document.getElementById('photoModalTitle').textContent = link.getAttribute('data-photo-title') || 'Photo';
    window.bootstrap.Modal.getOrCreateInstance(photoModal).show();
  });

  // Missing photo (photo.php or generate_panel.php answer 404): placeholder image
  document.addEventListener('error', function (event) {
    var img = event.target;
    if (img && img.tagName === 'IMG' && !img.hasAttribute('data-no-fallback') && img.src.indexOf('image_404') === -1 && /(photo|generate_panel)\.php/.test(img.src)) {
      img.src = '../style/image_404.jpg';
      img.classList.add('photo-missing');
    }
  }, true);

  /*
   * Create / edit windows: a button with data-bs-toggle="modal", data-bs-target="#id" and
   * data-fill='{"field": "value"}' fills the form of the window (data-title: its title).
   * The same JSON on an element with data-reopen-modal reopens a window after a refused save.
   */
  function fillForm(modal, values, title) {
    var form = modal.querySelector('form');
    if (!form) {
      return;
    }
    Object.keys(values).forEach(function (name) {
      form.querySelectorAll('[name="' + name + '"], [name="' + name + '[]"]').forEach(function (el) {
        var value = values[name];
        if (el.type === 'checkbox') {
          el.checked = value === true || value === 1 || value === '1';
        } else if (el.type === 'radio') {
          el.checked = el.value === String(value);
        } else if (el.multiple) {
          var list = Array.isArray(value) ? value.map(String) : [];
          Array.prototype.forEach.call(el.options, function (o) { o.selected = list.indexOf(o.value) !== -1; });
        } else if (el.type !== 'file') {
          el.value = value === null || value === undefined ? '' : value;
        }
      });
    });
    if (title) {
      var heading = modal.querySelector('.modal-title');
      if (heading) {
        heading.textContent = title;
      }
    }
    form.dispatchEvent(new CustomEvent('vigilo:filled', { detail: values }));
  }
  document.addEventListener('show.bs.modal', function (event) {
    var trigger = event.relatedTarget;
    if (trigger && trigger.hasAttribute('data-fill')) {
      fillForm(event.target, JSON.parse(trigger.getAttribute('data-fill')), trigger.getAttribute('data-title'));
    }
  });
  document.querySelectorAll('[data-reopen-modal]').forEach(function (el) {
    var modal = document.querySelector(el.getAttribute('data-reopen-modal'));
    if (modal && window.bootstrap) {
      fillForm(modal, JSON.parse(el.getAttribute('data-fill') || '{}'), el.getAttribute('data-title'));
      window.bootstrap.Modal.getOrCreateInstance(modal).show();
    }
  });

  // Deletion of a category: what to do with its observations (shown only when it is used)
  document.querySelectorAll('form[data-category-delete]').forEach(function (form) {
    function update() {
      var count = parseInt(form.elements.obs_count.value, 10) || 0;
      var move = form.querySelector('#obs_action_move').checked;
      form.querySelector('[data-when-used]').hidden = count === 0;
      form.querySelector('[data-when-unused]').hidden = count !== 0;
      form.querySelector('[data-obs-count]').textContent = count;
      form.elements.target_catid.required = count > 0 && move;
      form.elements.target_catid.disabled = count === 0 || !move;
    }
    form.addEventListener('vigilo:filled', function () {
      Array.prototype.forEach.call(form.elements.target_catid.options, function (o) {
        o.disabled = o.value !== '' && o.value === form.elements.cat_id.value;
      });
      update();
    });
    form.addEventListener('change', update);
    form.addEventListener('submit', function (event) {
      if (form.querySelector('#obs_action_delete').checked && parseInt(form.elements.obs_count.value, 10) > 0
          && !window.confirm('Supprimer définitivement ' + form.elements.obs_count.value + ' observation(s) et leurs photos ?')) {
        event.preventDefault();
      }
    });
  });

  /*
   * Bulk actions: <form data-bulk> with a select[name="bulk_action"]; the items are checkboxes
   * name="bulk_ids[]" form="<form id>" in the list. An option can have data-field="x" (shows the
   * [data-bulk-field="x"] elements) and data-confirm. [data-bulk-all] selects every item,
   * [data-bulk-count] shows the number of selected items.
   */
  document.querySelectorAll('form[data-bulk]').forEach(function (form) {
    var items = function () { return document.querySelectorAll('input[name="bulk_ids[]"][form="' + form.id + '"]'); };
    var all = form.querySelector('[data-bulk-all]');
    var action = form.elements.bulk_action;
    function update() {
      var list = items();
      var checked = Array.prototype.filter.call(list, function (el) { return el.checked; }).length;
      form.querySelectorAll('[data-bulk-count]').forEach(function (el) { el.textContent = checked; });
      if (all) {
        all.checked = checked > 0 && checked === list.length;
        all.indeterminate = checked > 0 && checked < list.length;
        all.disabled = list.length === 0;
      }
      var option = action.options[action.selectedIndex];
      var field = option ? option.getAttribute('data-field') : null;
      form.querySelectorAll('[data-bulk-field]').forEach(function (el) {
        var shown = el.getAttribute('data-bulk-field') === field;
        el.hidden = !shown;
        el.querySelectorAll('select, input').forEach(function (input) { input.disabled = !shown; input.required = shown; });
      });
      form.querySelector('[type="submit"]').disabled = checked === 0 || !action.value;
    }
    if (all) {
      all.addEventListener('change', function () {
        items().forEach(function (el) { el.checked = all.checked; });
        update();
      });
    }
    document.addEventListener('change', function (event) {
      if (event.target.matches && event.target.matches('input[name="bulk_ids[]"]')) {
        update();
      }
    });
    action.addEventListener('change', update);
    form.addEventListener('submit', function (event) {
      var option = action.options[action.selectedIndex];
      var message = option && option.getAttribute('data-confirm');
      var count = form.querySelector('[data-bulk-count]');
      if (message && !window.confirm(message.replace('%n', count ? count.textContent : ''))) {
        event.preventDefault();
      }
    });
    update();
  });

  // Report form (page Rapports): quick periods
  document.querySelectorAll('[data-report-form]').forEach(function (form) {
    function day(d) {
      return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }
    form.querySelectorAll('[data-report-period]').forEach(function (button) {
      button.addEventListener('click', function () {
        var now = new Date();
        var from = new Date(now);
        var to = new Date(now);
        var period = button.getAttribute('data-report-period');
        if (period === '3m') {
          from.setMonth(from.getMonth() - 3);
          from.setDate(from.getDate() + 1);
        } else if (period === '12m') {
          from.setFullYear(from.getFullYear() - 1);
          from.setDate(from.getDate() + 1);
        } else if (period === 'year') {
          from = new Date(now.getFullYear(), 0, 1);
        } else if (period === 'lastyear') {
          from = new Date(now.getFullYear() - 1, 0, 1);
          to = new Date(now.getFullYear() - 1, 11, 31);
        }
        form.querySelector('[name="from"]').value = day(from);
        form.querySelector('[name="to"]').value = day(to);
      });
    });
  });

  // Bootstrap tooltips
  if (window.bootstrap) {
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
      new window.bootstrap.Tooltip(el);
    });
  }
})();
