/* StellarSavers Social Commerce — Front-end Tracker */
(function () {
  'use strict';
  if (typeof SSCA === 'undefined') return;

  // Track clicks on SSCA tracking links in page content
  document.addEventListener('click', function (e) {
    const link = e.target.closest('a[href*="ssca-track"]');
    if (!link) return;

    const match = link.href.match(/ssca-track\/click\/(\d+)/);
    if (match) {
      // Let redirect happen naturally — server handles tracking
      // Nothing to do here; redirect endpoint records click
    }
  });

  // Store session attribution from URL if coming from social
  const urlParams = new URLSearchParams(window.location.search);
  const sscaPid   = urlParams.get('ssca_pid');
  if (sscaPid) {
    try {
      sessionStorage.setItem('ssca_last_pid', sscaPid);
    } catch (e) {}
  }
})();
