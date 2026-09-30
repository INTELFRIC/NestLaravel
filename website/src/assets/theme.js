/* Runs before first paint (loaded in <head>) to avoid a theme flash. Default = dark; "system" follows the OS. */
(function () {
  var root = document.documentElement;
  root.classList.remove('no-js');
  try {
    var t = localStorage.getItem('nl-theme') || 'dark';
    if (t === 'dark' || t === 'light') root.setAttribute('data-theme', t);
    root.setAttribute('data-theme-pref', t);
  } catch (e) { root.setAttribute('data-theme', 'dark'); }
})();
