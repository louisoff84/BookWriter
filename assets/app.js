(() => {
  const page = document.body.dataset.page || 'home';
  const css = document.createElement('link');
  css.rel = 'stylesheet';
  css.href = 'assets/pages.css';
  document.head.append(css);

  const common = document.createElement('script');
  common.src = 'assets/common.js';
  common.onload = () => {
    const map = {
      home: 'home',
      explore: 'explore',
      login: 'auth',
      register: 'auth',
      studio: 'studio',
      reader: 'reader',
      import: 'import',
      account: 'account',
      developers: 'developers'
    };
    const name = map[page];
    if (!name) return;
    const script = document.createElement('script');
    script.src = `assets/page-${name}.js`;
    document.body.append(script);
  };
  document.body.append(common);
})();
