/** Returns true when a URL should use React Router Link (internal app path). */
export function isInternalAppPath(url: string): boolean {
  if (!url) return true;
  const trimmed = url.trim();
  return trimmed.startsWith('/') && !trimmed.startsWith('//');
}
