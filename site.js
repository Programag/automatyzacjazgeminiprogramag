// PaperlyAI – strony informacyjne: motyw z aplikacji i dane operatora.
// Przy wgrywaniu na hosting dane operatora są wpisywane z sekretów (OPERATOR_NAME, OPERATOR_ADDRESS, OPERATOR_NIP, CONTACT_EMAIL).
// Gdy strona jest otwarta bez tego kroku (np. podgląd z repozytorium), uzupełniamy wartości domyślne, a puste wiersze ukrywamy.
(function () {
  try { var t = localStorage.getItem('officeflow_theme'); if (t === 'light' || t === 'dark') document.documentElement.dataset.theme = t; } catch (e) { /* brak dostępu */ }
  function fill() {
    var host = location.hostname.replace(/^www\./, '') || 'paperlyai.pl';
    var d = { SITE_URL: location.origin, SITE_HOST: location.host, OPERATOR_NAME: 'PaperlyAI', CONTACT_EMAIL: 'kontakt@' + host };
    Array.prototype.forEach.call(document.querySelectorAll('[data-req]'), function (el) {
      if (el.innerHTML.indexOf('{{' + el.getAttribute('data-req') + '}}') >= 0) el.parentNode.removeChild(el);
    });
    var rep = function (s) { return s.replace(/\{\{([A-Z_]+)\}\}/g, function (m, k) { return d[k] != null ? d[k] : ''; }); };
    var w = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    var n; while ((n = w.nextNode())) if (n.nodeValue.indexOf('{{') >= 0) n.nodeValue = rep(n.nodeValue);
    Array.prototype.forEach.call(document.querySelectorAll('a[href*="{{"]'), function (a) { a.setAttribute('href', rep(a.getAttribute('href'))); });
    if (document.title.indexOf('{{') >= 0) document.title = rep(document.title);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fill); else fill();
})();
