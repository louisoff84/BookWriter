(()=>{const {$$,toast}=BW;$$('[data-copy]').forEach(b=>b.addEventListener('click',async()=>{await navigator.clipboard.writeText(b.dataset.copy);toast('Copié.','success')}))})();
