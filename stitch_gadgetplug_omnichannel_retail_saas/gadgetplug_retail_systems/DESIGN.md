---
name: GadgetPlug Retail Systems
colors:
  surface: '#f8f9fa'
  surface-dim: '#d9dadb'
  surface-bright: '#f8f9fa'
  surface-container-lowest: '#ffffff'
  surface-container-low: '#f3f4f5'
  surface-container: '#edeeef'
  surface-container-high: '#e7e8e9'
  surface-container-highest: '#e1e3e4'
  on-surface: '#191c1d'
  on-surface-variant: '#3f4a3a'
  inverse-surface: '#2e3132'
  inverse-on-surface: '#f0f1f2'
  outline: '#6f7b68'
  outline-variant: '#becab5'
  surface-tint: '#016e00'
  primary: '#016c00'
  on-primary: '#ffffff'
  primary-container: '#018800'
  on-primary-container: '#f8ffef'
  inverse-primary: '#6cdf59'
  secondary: '#9d4300'
  on-secondary: '#ffffff'
  secondary-container: '#fd761a'
  on-secondary-container: '#5c2400'
  tertiary: '#466800'
  on-tertiary: '#ffffff'
  tertiary-container: '#598300'
  on-tertiary-container: '#030600'
  error: '#ba1a1a'
  on-error: '#ffffff'
  error-container: '#ffdad6'
  on-error-container: '#93000a'
  primary-fixed: '#88fc72'
  primary-fixed-dim: '#6cdf59'
  on-primary-fixed: '#002200'
  on-primary-fixed-variant: '#015300'
  secondary-fixed: '#ffdbca'
  secondary-fixed-dim: '#ffb690'
  on-secondary-fixed: '#341100'
  on-secondary-fixed-variant: '#783200'
  tertiary-fixed: '#acf900'
  tertiary-fixed-dim: '#97da00'
  on-tertiary-fixed: '#121f00'
  on-tertiary-fixed-variant: '#344e00'
  background: '#f8f9fa'
  on-background: '#191c1d'
  surface-variant: '#e1e3e4'
typography:
  display-lg:
    fontFamily: Montserrat
    fontSize: 48px
    fontWeight: '700'
    lineHeight: '1.2'
    letterSpacing: -0.02em
  headline-lg:
    fontFamily: Montserrat
    fontSize: 32px
    fontWeight: '700'
    lineHeight: 40px
  headline-lg-mobile:
    fontFamily: Montserrat
    fontSize: 24px
    fontWeight: '700'
    lineHeight: 32px
  title-md:
    fontFamily: Montserrat
    fontSize: 18px
    fontWeight: '600'
    lineHeight: 24px
  body-md:
    fontFamily: Inter
    fontSize: 16px
    fontWeight: '400'
    lineHeight: 24px
  body-sm:
    fontFamily: Inter
    fontSize: 14px
    fontWeight: '400'
    lineHeight: 20px
  label-bold:
    fontFamily: Inter
    fontSize: 12px
    fontWeight: '600'
    lineHeight: 16px
    letterSpacing: 0.05em
  numeric-data:
    fontFamily: Montserrat
    fontSize: 16px
    fontWeight: '600'
    lineHeight: 24px
rounded:
  sm: 0.25rem
  DEFAULT: 0.5rem
  md: 0.75rem
  lg: 1rem
  xl: 1.5rem
  full: 9999px
spacing:
  base: 4px
  xs: 4px
  sm: 8px
  md: 16px
  lg: 24px
  xl: 32px
  container-max: 1280px
  gutter: 20px
---

## Brand & Style

The design system is built for the high-velocity Nigerian retail market, where efficiency, trust, and clarity are paramount. The aesthetic is **Corporate / Modern**, prioritizing a high-performance SaaS feel that remains accessible to local business owners.

The visual narrative balances the grounded reliability of "Deep Retail Green" with the energetic urgency of "Action Orange" and "Neon Tech." This combination signals a product that is both professional enough for enterprise procurement and agile enough for fast-paced retail operations. The user interface utilizes a clean, "White-Label" approach on surfaces to ensure that data—inventory counts, prices, and barcodes—remains the primary focus.

**Key Attributes:**
- **Reliability:** Grounded through structured layouts and neutral backgrounds.
- **Vibrancy:** Infused via neon accents and bold brand colors to keep users engaged.
- **Precision:** Expressed through sharp typography and purposeful spacing.

## Colors

The palette is designed to categorize actions and information hierarchy through distinct color roles:

- **Primary (#068B03):** Used for core branding, successful states, and primary navigation elements. It represents the "Go" signal of retail health.
- **Secondary (#F97316):** Reserved strictly for primary call-to-actions (CTAs) and conversion points like "Next," "Confirm," or "Payout."
- **Accent (#B1FF00):** A high-visibility "Neon Tech" used sparingly for tooltips, status indicators for active sessions, or highlights in data visualizations.
- **Neutral / Background (#F9FAFB):** A cool-toned off-white that reduces eye strain and provides a soft foundation for the primary white surface cards.

## Typography

The typographic strategy employs **Montserrat** for all brand-facing and numerical data points. Its geometric structure ensures that prices and quantities are legible at a glance. **Inter** handles the functional UI text, providing high legibility for long lists and complex forms.

- **Headers:** Always Montserrat. Use semi-bold or bold weights to establish hierarchy.
- **Functional UI:** Always Inter. Use for field labels, helper text, and secondary UI metadata.
- **Numerical Values:** Use Montserrat to emphasize fiscal data (costs, selling prices, quantities).

## Layout & Spacing

The design system utilizes a **12-column fluid grid** for desktop and a **4-column grid** for mobile. 

- **Desktop:** 24px margins with 20px gutters. Cards should typically span 4, 6, or 12 columns.
- **Mobile:** 16px margins with 12px gutters. All primary actions and form fields should expand to 100% width.
- **Rhythm:** Spacing follows a 4px baseline. Use `16px (md)` for standard padding inside cards and `24px (lg)` for vertical separation between sections.

## Elevation & Depth

To maintain a "Premium SaaS" aesthetic, depth is created through **Ambient Shadows** rather than heavy borders.

- **Level 0 (Background):** #F9FAFB.
- **Level 1 (Cards/Surfaces):** White (#FFFFFF) with a very soft, diffused shadow: `0px 4px 20px rgba(0, 0, 0, 0.04)`.
- **Level 2 (Dropdowns/Modals):** White (#FFFFFF) with a more defined shadow to suggest proximity to the user: `0px 10px 30px rgba(0, 0, 0, 0.08)`.
- **Interactive States:** Hovering over a card should slightly increase the shadow spread and lift the element by 1px to provide tactile feedback.

## Shapes

The design system adopts a **Rounded (0.5rem)** logic. This softens the "industrial" feel of retail software while maintaining a professional structure.

- **Standard Elements:** (Buttons, Input Fields, Checkboxes) use a `0.5rem (8px)` radius.
- **Large Containers:** (Main Content Cards) use `1rem (16px)` radius.
- **Status Pills:** Use a fully rounded/pill shape to distinguish them from interactive buttons.

## Components

### Buttons
- **Primary Action:** Background Action Orange (#F97316), White Text, Bold Montserrat.
- **Secondary Action:** White background, 1px border using Grey-200, Primary Green Text.
- **Ghost Action:** No background or border, Action Orange text for "Delete" or "Remove" (with subtle red tinting if destructive).

### Input Fields
- **Container:** White background, 1px border (#E5E7EB), 8px corner radius.
- **Focus State:** 1px border in Primary Green (#068B03) with a 3px soft glow (20% opacity).
- **Labels:** Inter, 12px, Semi-bold, Grey-700.

### Cards & Procurement Items
- Use a white surface with a 1px soft grey border. 
- Group related data (e.g., Barcode + Product Name) with subtle horizontal dividers.
- **Iconography:** Use 20px stroked icons for sidebar navigation and 16px icons for in-line actions (like the trash icon or camera for barcode scanning).

### Steppers
- Use a horizontal progress bar at the top of multi-step flows (like Procurement).
- **Active Step:** Primary Green circle with a checkmark or number.
- **Pending Step:** Grey circle with Montserrat font.