function formatDate(iso, lang = 'fr') {
  if (!iso) return '';
  try {
    return new Date(iso).toLocaleString(
      lang === 'fr' ? 'fr-FR' : 'en-US',
      { dateStyle: 'short', timeStyle: 'short' }
    );
  } catch {
    return iso;
  }
}

export { formatDate };
