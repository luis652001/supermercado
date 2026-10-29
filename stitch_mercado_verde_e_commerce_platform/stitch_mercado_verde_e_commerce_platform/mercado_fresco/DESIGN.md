---
name: Mercado Fresco
colors:
  surface: '#f8f9ff'
  surface-dim: '#d0dbed'
  surface-bright: '#f8f9ff'
  surface-container-lowest: '#ffffff'
  surface-container-low: '#eff4ff'
  surface-container: '#e6eeff'
  surface-container-high: '#dee9fc'
  surface-container-highest: '#d9e3f6'
  on-surface: '#121c2a'
  on-surface-variant: '#3f4942'
  inverse-surface: '#27313f'
  inverse-on-surface: '#eaf1ff'
  outline: '#6f7a71'
  outline-variant: '#bfc9c0'
  surface-tint: '#186c45'
  primary: '#005131'
  on-primary: '#ffffff'
  primary-container: '#176b45'
  on-primary-container: '#99e9b9'
  inverse-primary: '#88d7a8'
  secondary: '#006e25'
  on-secondary: '#ffffff'
  secondary-container: '#80f98b'
  on-secondary-container: '#007327'
  tertiary: '#3e4943'
  on-tertiary: '#ffffff'
  tertiary-container: '#55615a'
  on-tertiary-container: '#cfdbd3'
  error: '#ba1a1a'
  on-error: '#ffffff'
  error-container: '#ffdad6'
  on-error-container: '#93000a'
  primary-fixed: '#a4f4c3'
  primary-fixed-dim: '#88d7a8'
  on-primary-fixed: '#002111'
  on-primary-fixed-variant: '#005232'
  secondary-fixed: '#83fc8e'
  secondary-fixed-dim: '#66df75'
  on-secondary-fixed: '#002106'
  on-secondary-fixed-variant: '#00531a'
  tertiary-fixed: '#d9e6dd'
  tertiary-fixed-dim: '#bdcac1'
  on-tertiary-fixed: '#131e19'
  on-tertiary-fixed-variant: '#3e4943'
  background: '#f8f9ff'
  on-background: '#121c2a'
  surface-variant: '#d9e3f6'
typography:
  display:
    fontFamily: Plus Jakarta Sans
    fontSize: 40px
    fontWeight: '800'
    lineHeight: 48px
    letterSpacing: -0.02em
  headline-lg:
    fontFamily: Plus Jakarta Sans
    fontSize: 32px
    fontWeight: '700'
    lineHeight: 40px
    letterSpacing: -0.015em
  headline-lg-mobile:
    fontFamily: Plus Jakarta Sans
    fontSize: 26px
    fontWeight: '700'
    lineHeight: 32px
    letterSpacing: -0.01em
  headline-md:
    fontFamily: Plus Jakarta Sans
    fontSize: 22px
    fontWeight: '700'
    lineHeight: 28px
    letterSpacing: -0.01em
  headline-sm:
    fontFamily: Plus Jakarta Sans
    fontSize: 18px
    fontWeight: '600'
    lineHeight: 24px
  title-md:
    fontFamily: Plus Jakarta Sans
    fontSize: 16px
    fontWeight: '600'
    lineHeight: 22px
  body-lg:
    fontFamily: Plus Jakarta Sans
    fontSize: 16px
    fontWeight: '400'
    lineHeight: 24px
  body-md:
    fontFamily: Plus Jakarta Sans
    fontSize: 14px
    fontWeight: '400'
    lineHeight: 20px
  body-sm:
    fontFamily: Plus Jakarta Sans
    fontSize: 12px
    fontWeight: '400'
    lineHeight: 16px
  label-lg:
    fontFamily: Plus Jakarta Sans
    fontSize: 14px
    fontWeight: '600'
    lineHeight: 18px
    letterSpacing: 0.01em
  label-md:
    fontFamily: Plus Jakarta Sans
    fontSize: 12px
    fontWeight: '600'
    lineHeight: 16px
    letterSpacing: 0.02em
  label-sm:
    fontFamily: Plus Jakarta Sans
    fontSize: 10px
    fontWeight: '700'
    lineHeight: 12px
    letterSpacing: 0.04em
  price-lg:
    fontFamily: Plus Jakarta Sans
    fontSize: 20px
    fontWeight: '800'
    lineHeight: 24px
    letterSpacing: -0.01em
  price-md:
    fontFamily: Plus Jakarta Sans
    fontSize: 16px
    fontWeight: '700'
    lineHeight: 20px
rounded:
  sm: 0.25rem
  DEFAULT: 0.5rem
  md: 0.75rem
  lg: 1rem
  xl: 1.5rem
  full: 9999px
spacing:
  gutter: 1rem
  gutter-desktop: 1.5rem
  margin: 1rem
  margin-tablet: 2rem
  margin-desktop: 3rem
  space-xs: 0.25rem
  space-sm: 0.5rem
  space-md: 1rem
  space-lg: 1.5rem
  space-xl: 2.5rem
---

## Brand & Style

This design system serves an online supermarket operating in Guatemala, balancing the vitality of local open-air markets (*mercados*) with the speed, efficiency, and reliability of modern e-commerce.

### Persona & Emotional Response
The interface radiates freshness, hygiene, and domestic trust. It eliminates cognitive friction for everyday grocery runs while celebrating local produce and staple pantry items. The experience should feel crisp, dependable, and warmly familiar, evoking the peace of mind of hand-selected fruits, vegetables, and household goods arriving on schedule.

### Design Style: Modern Utility with Tactile Warmth
Drawing from **Modern Utility** and light **Tactile UI**, the system employs:
- High contrast and pristine white/mint surfaces for cleanliness.
- Generous tap targets engineered for on-the-go single-hand mobile grocery shopping.
- Crisp container cards with subtle structural micro-borders to keep complex multisku inventories readable.
- High-visibility status tokens and pricing formats adapted to local standards.

## Colors

The palette pairs agrarian depth with crisp digital clarity, leaning heavily on verdant tones to reinforce freshness and organic quality.

### Roles & Assignments
- **Primary (`#176B45` - Deep Forest Green):** Anchors brand authority, top headers, main conversion action buttons, bottom navigation active states, and primary checkout flows.
- **Secondary (`#28A745` - Fresh Mint Green):** Applied to positive feedback, stock confirmations, add-to-cart badges, promotion flags, and active delivery stages.
- **Tertiary (`#E8F5EC` - Soft Background Mint):** Used for soft canvas backgrounds, active category pill fills, tinted notification panels, and delivery step backdrops.
- **Neutral Dark (`#1F2937` - Charcoal Gray):** Dedicated to high-contrast typography, icons, strong outlines, and price text to ensure effortless readability under bright sunlight.
- **Neutral Light (`#F3F4F6` - Off-White/Cool Gray):** Primary surface fill for search inputs, inactive pill indicators, card borders, and utility separators.
- **System Accents:** 
  - Warning Amber (`#F59E0B`): Expiring discounts, low-stock warnings, and prep-in-progress alerts.
  - Error Crimson (`#EF4444`): Out-of-stock items, payment validation errors, and item removal actions.

## Typography

The design system exclusively utilizes **Plus Jakarta Sans** for its clear geometry, friendly humanist touches, and legibility at micro sizes on mobile screens.

### Currency & Local Number Formatting
- All product pricing follows the Guatemalan Quetzal convention: `Q ##.##` (e.g., `Q 14.50`, `Q 120.00`).
- The currency symbol `Q` shares the font weight of the figure, spaced by a single non-breaking space.
- Strikethrough comparison prices use `body-sm` in `#1F2937` with 50% opacity.

## Layout & Spacing

A mobile-first responsive grid ensures fast scanning and checkout ease.

### Grid & Form Factors
- **Mobile (< 768px):** 4-column fluid layout, `margin`: `1rem` (16px), `gutter`: `1rem` (16px). Product listings display primarily in a 2-column card grid or horizontal scrolling rails.
- **Tablet (768px - 1024px):** 8-column layout, `margin-tablet`: `2rem` (32px), `gutter`: `1rem` (16px). Product listings adapt to 3 or 4 columns.
- **Desktop (> 1024px):** 12-column layout capped at `1280px` max-width, `margin-desktop`: `3rem` (48px), `gutter-desktop`: `1.5rem` (24px).

### Bottom Clearance
To support mobile app interactions and pinned bottom bars (navigation and checkout summaries), all scrollable views must include a dynamic bottom padding of at least `5rem` (`80px`).

## Elevation & Depth

Visual hierarchy uses a balance of crisp structural borders and soft, diffused shadows to maintain an airy, daylight-lit aesthetic.

### Depth Levels
- **Level 0 (Flat / Canvas):** Main background surfaces (`#FFFFFF` or `#E8F5EC`).
- **Level 1 (Cards & Listings):** Background `#FFFFFF` with a crisp outline `1px solid #F3F4F6` and an ambient shadow `0 2px 8px -2px rgba(31, 41, 55, 0.05)`.
- **Level 2 (Floating Controls & Dropdowns):** Stepper buttons, quantity selectors, search overlays. Shadow: `0 4px 16px -4px rgba(23, 107, 69, 0.12)`.
- **Level 3 (Tactile Bottom Navigation & Sticky Bars):** Pinned cart checkout strips and bottom bars. Shadow: `0 -4px 20px 0 rgba(31, 41, 55, 0.08)`, creating upward separation without harsh divider lines.

## Shapes

The shape system leverages rounded containers for products, nested with full-pill geometry for actions and contextual indicators.

- **Base Radii (`roundedness: 2`):** Cards, input fields, quantity counter boxes, and banners use `0.5rem` (8px). Larger dialogs, order detail sheets, and bottom sheets adopt `rounded-lg` (`1rem` / 16px) or `rounded-xl` (`1.5rem` / 24px) on their top edge.
- **Pill Geometry (9999px):** Applied systematically to category badges, delivery progress chips, quick-add floating badges, and primary action buttons.

## Components

### Buttons
- **Primary Button:** Background `#176B45`, text `#FFFFFF`, roundedness `9999px` (pill), height `48px` (mobile standard) or `40px` (desktop). Active state provides a slight tactile scale down (`0.98`).
- **Secondary / Ghost Button:** Transparent background, `1.5px solid #176B45`, text `#176B45`.
- **Quick Add "+":** Compact circle (`36px × 36px`) or pill button with text "Agregar". Background `#28A745`, text `#FFFFFF`. When active, morphs smoothly into an inline quantity stepper (`-` | count | `+`) backed by `#E8F5EC`.

### Badges & Category Pills
- **Category Badges:** Filter pills with `padding: 0.5rem 1rem`, pill-shaped. Inactive: `#F3F4F6` fill with `#1F2937` text. Selected/Active: `#176B45` fill with `#FFFFFF` text or `#E8F5EC` fill with `#176B45` bold text.
- **Delivery Status Pills:** 
  - *En camino (On the way):* Background `#E8F5EC`, text `#176B45`, pulsing dot indicator `#28A745`.
  - *En preparación (Preparing):* Background `#FEF3C7`, text `#D97706`.
  - *Entregado (Delivered):* Background `#DEF7EC`, text `#03543F`.

### Product Cards
- Contained white boxes on `#E8F5EC` or `#FFFFFF` canvas. 
- Top: Square product photography area with subtle `#F3F4F6` background. Includes top-left discount tags (`-15%`) in Error Crimson pill badges.
- Center: Product title (`title-md`), weight/unit descriptor (`body-sm`, e.g., "Mano de 5 unidades" or "1 lb").
- Bottom: Price (`price-md` in `Q ##.##`) paired inline with the tactile add button.

### Form Inputs
- Background `#FFFFFF`, border `1.5px solid #F3F4F6`, roundedness `0.5rem`.
- Focus state: Border color changes to `#176B45` with a subtle box-shadow ring `0 0 0 3px rgba(23, 107, 69, 0.15)`.
- Global search bar: Pill-shaped (`9999px`), left-aligned magnifying glass icon in `#1F2937` (60% opacity), placeholder: "Buscar frutas, verduras, abarrotes...".

### Bottom Navigation Bar (Mobile)
- Fixed at screen bottom, height `64px`, background `#FFFFFF` with Level 3 elevation shadow.
- 4 to 5 key destinations: *Inicio* (Home), *Categorías* (Categories), *Mis Pedidos* (Orders), *Carrito* (Cart).
- Inactive icons: `#1F2937` (40% opacity).
- Active item: `#176B45` with a compact dot or rounded background pill accent.
- Cart icon holds a floating badge counter in `#28A745` with bold white text.