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

  // Bootstrap tooltips
  if (window.bootstrap) {
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
      new window.bootstrap.Tooltip(el);
    });
  }
})();
