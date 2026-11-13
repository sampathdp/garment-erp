/**
 * Same-origin, in-app path check for anything that is navigated to (post-login `?next=`,
 * notification and dashboard deep links). Returns the path, or null when it could leave the app.
 *
 * `startsWith("/") && !startsWith("//")` is not enough: browsers strip TAB/CR/LF anywhere in a
 * URL and treat "\" like "/", so "/\t/evil.example" or "/\\evil.example" become "//evil.example"
 * (a protocol-relative URL to another site). Such input is rejected, and the result is resolved
 * against a fixed origin to confirm it stays on it.
 */
export function safeInAppPath(value: string | null | undefined): string | null {
  if (!value || value.length > 2000) return null;
  // Control characters, whitespace and backslashes never appear in our own paths.
  if (/[\u0000- \u007f\\]/.test(value)) return null;
  if (!value.startsWith("/") || value.startsWith("//")) return null;

  const base = "https://app.invalid";
  let url: URL;
  try {
    url = new URL(value, base);
  } catch {
    return null;
  }
  if (url.origin !== base) return null;

  return url.pathname + url.search + url.hash;
}
