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

  // Bootstrap tooltips
  if (window.bootstrap) {
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
      new window.bootstrap.Tooltip(el);
    });
  }
})();
