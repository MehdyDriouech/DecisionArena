function renderMarkdown(text) {
  if (!text) return '';
  let html = text
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/^######\s(.+)/gm, '<h6>$1</h6>')
    .replace(/^#####\s(.+)/gm, '<h5>$1</h5>')
    .replace(/^####\s(.+)/gm, '<h4>$1</h4>')
    .replace(/^###\s(.+)/gm, '<h3>$1</h3>')
    .replace(/^##\s(.+)/gm, '<h2>$1</h2>')
    .replace(/^#\s(.+)/gm, '<h1>$1</h1>')
    .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
    .replace(/\*(.+?)\*/g, '<em>$1</em>')
    .replace(/`(.+?)`/g, '<code>$1</code>')
    .replace(/^[-*]\s(.+)/gm, '<li>$1</li>');

  html = html.replace(/(<li>[\s\S]*?<\/li>(\n|$))+/g, (m) => `<ul>${m}</ul>`);
  html = html
    .replace(/\n\n/g, '</p><p>')
    .replace(/^(?!<[hul]|<\/[hul])(.+)/gm, (m) => (m.startsWith('<') ? m : m));

  return `<p>${html}</p>`;
}

export { renderMarkdown };
