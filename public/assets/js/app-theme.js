// Customize the web application's palette here. Applied through CSS custom properties.
class AppTheme {
  static palettes = Object.freeze({
    blue: {name: 'Blue', primary: '#1f5be0', dark: '#1748b8', soft: '#eaf2ff', border: '#c9daf5', text: '#173d69'},
    green: {name: 'Green', primary: '#15945f', dark: '#0f7449', soft: '#e9f8f1', border: '#bfe4d2', text: '#174f3b'},
    purple: {name: 'Purple', primary: '#7651c9', dark: '#5c3ba8', soft: '#f1ecfc', border: '#d9cdf3', text: '#493879'},
    orange: {name: 'Orange', primary: '#e07824', dark: '#bd5d15', soft: '#fff1e5', border: '#f2d1b5', text: '#754319'},
  });

  static apply(value) {
    const selected = Object.hasOwn(this.palettes, value) ? value : 'blue';
    const palette = this.palettes[selected];
    const root = document.documentElement;
    root.dataset.theme = selected;
    for (const [property, color] of Object.entries({
      primary: palette.primary, 'primary-dark': palette.dark,
      soft: palette.soft, border: palette.border, text: palette.text,
    })) root.style.setProperty(`--theme-${property}`, color);
    localStorage.setItem('wg.theme', selected);
    return selected;
  }
}
