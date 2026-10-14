---
name: FlowTask Modern Corporate
colors:
  surface: '#f8f9ff'
  surface-dim: '#cbdbf6'
  surface-bright: '#f8f9ff'
  surface-container-lowest: '#ffffff'
  surface-container-low: '#eff4ff'
  surface-container: '#e5eeff'
  surface-container-high: '#dce9ff'
  surface-container-highest: '#d3e4fe'
  on-surface: '#0b1c30'
  on-surface-variant: '#434655'
  inverse-surface: '#213146'
  inverse-on-surface: '#ebf1ff'
  outline: '#737686'
  outline-variant: '#c3c6d7'
  surface-tint: '#1b55d0'
  primary: '#003594'
  on-primary: '#ffffff'
  primary-container: '#2563eb'
  on-primary-container: '#eeefff'
  inverse-primary: '#b4c5ff'
  secondary: '#006c49'
  on-secondary: '#ffffff'
  secondary-container: '#6cf8bb'
  on-secondary-container: '#00714d'
  tertiary: '#593600'
  on-tertiary: '#ffffff'
  tertiary-container: '#996100'
  on-tertiary-container: '#ffeedd'
  error: '#ba1a1a'
  on-error: '#ffffff'
  error-container: '#ffdad6'
  on-error-container: '#93000a'
  primary-fixed: '#dbe1ff'
  primary-fixed-dim: '#b4c5ff'
  on-primary-fixed: '#00174b'
  on-primary-fixed-variant: '#003ea8'
  secondary-fixed: '#6ffbbe'
  secondary-fixed-dim: '#4edea3'
  on-secondary-fixed: '#002113'
  on-secondary-fixed-variant: '#005236'
  tertiary-fixed: '#ffddb8'
  tertiary-fixed-dim: '#fabb6b'
  on-tertiary-fixed: '#2a1700'
  on-tertiary-fixed-variant: '#653e00'
  background: '#f8f9ff'
  on-background: '#0b1c30'
  surface-variant: '#d3e3ff'
typography:
  display-hero:
    fontFamily: Plus Jakarta Sans
    fontSize: 56px
    fontWeight: '800'
    lineHeight: 64px
  headline-lg:
    fontFamily: Plus Jakarta Sans
    fontSize: 32px
    fontWeight: '700'
    lineHeight: 40px
  headline-lg-mobile:
    fontFamily: Plus Jakarta Sans
    fontSize: 24px
    fontWeight: '700'
    lineHeight: 32px
  headline-md:
    fontFamily: Plus Jakarta Sans
    fontSize: 20px
    fontWeight: '700'
    lineHeight: 28px
  stat-metric:
    fontFamily: Plus Jakarta Sans
    fontSize: 28px
    fontWeight: '800'
    lineHeight: 32px
  title-sm:
    fontFamily: Plus Jakarta Sans
    fontSize: 16px
    fontWeight: '600'
    lineHeight: 24px
  body-md:
    fontFamily: Inter
    fontSize: 14px
    fontWeight: '400'
    lineHeight: 20px
  body-sm:
    fontFamily: Inter
    fontSize: 13px
    fontWeight: '400'
    lineHeight: 18px
  label-md:
    fontFamily: Inter
    fontSize: 12px
    fontWeight: '500'
    lineHeight: 16px
  label-sm:
    fontFamily: Inter
    fontSize: 11px
    fontWeight: '600'
    lineHeight: 14px
rounded:
  sm: 0.125rem
  DEFAULT: 0.25rem
  md: 0.375rem
  lg: 0.5rem
  xl: 0.75rem
  full: 9999px
spacing:
  gutter: 1.25rem
  margin: 1.5rem
  space-xs: 0.25rem
  space-sm: 0.5rem
  space-md: 1rem
  space-lg: 1.5rem
  space-xl: 2rem
---

## Brand & Style
FlowTask represents a high-efficiency project orchestration and enterprise workflow brand. Its visual language merges **Corporate Modernism** with subtle high-performance SaaS cues: crisp, systematic card layouts, tinted low-contrast neutral surfaces, and intentional accent pops that convey productivity, confidence, and momentum.

The brand targets product managers, engineering directors, and agency leads who demand clarity amidst high information density. The emotional tone is dependable, focused, and frictionless—eliminating unnecessary clutter while maintaining approachable elegance through softened geometry and tactile feedback states.

## Colors
The palette leverages a cool-tinted Material 3 structure rooted in high-chroma cobalt blue `#004ac6` (`#2563eb` active container), supported by semantic emerald `#006c49` for positive progression and warm amber `#784b00` for active review states.

- **Background & Containers:** The base canvas is `#f8f9ff`. Elevation is communicated through nested tints: `#ffffff` for floating elevated cards, descending to `#eff4ff` and `#e5eeff` for inset metadata modules, search bars, and pill counters.
- **Hierarchy of Ink:** High-priority headlines and key metrics utilize `#0b1c30`, secondary body text and metadata use `#434655`, and supporting iconography or non-selected dividers rely on `#737686`.
- **Status Semantics:** Errors and urgent project badges use `#ba1a1a` on `#ffdad6`. Milestones and completed ratios use `#006c49` paired with `#6cf8bb`.

## Typography
The system enforces a dual-type hierarchy: **Plus Jakarta Sans** governs display titles, card headers, and stat counters, delivering clean geometric authority. **Inter** handles data tables, forms, metadata tags, and dense body descriptions to maximize legibility at 11–14px ranges.

- **Metrics & KPIs:** Large stats (`stat-metric`) use tight letter-spacing with an 800 weight to stand out across dashboards.
- **Micro-labels:** Category headers, status chips, and progress indicators rely on `label-sm` (11px, 600 weight) in uppercase or semi-bold sentence case with wider tracking (`tracking-wider` on navigation subtitles).

## Layout & Spacing
The layout follows an asymmetric split-screen model with a fixed 256px (`w-64`) desktop navigation rail and a flexible fluid content canvas capped at a `max-w-7xl` central container.

- **Rhythm & Grid:** Section containers use a standard 24px (`space-lg`) gap. Bento project cards and KPI blocks sit in a responsive auto-flowing grid: 1 column on mobile, 2 columns on tablet, and 3 or 4 columns on desktop layouts.
- **Inner Padding:** Surface cards use `p-space-md` (16px) for compact summary widgets and `p-space-lg` (24px) for full bento project cards. Inset metrics (e.g. task and budget panels) use `py-space-sm px-space-sm` (8px).

## Elevation & Depth
Depth is constructed using **Tonal Layering** combined with ultra-soft ambient drop shadows, rather than rigid structural borders:

1. **Base Layer (Canvas):** `bg-background` (`#f8f9ff`) sets a cool tinted baseline.
2. **Surface Layer (Cards & Sidebar):** `bg-surface-container-lowest` (`#ffffff`) elevated with an ambient shadow `0 1px 8px rgba(0,0,0,0.04)` (`shadow-sm`).
3. **Floating Top Bar:** Semi-transparent glassmorphic header using `bg-surface-container-lowest/80` with `backdrop-blur-xl` and a subtle hairline shadow.
4. **Interactive Hover States:** Cards elevate via `hover:-translate-y-0.5 hover:shadow-md transition-all`, creating a subtle magnetic lift.
5. **Inset Fields & Indicators:** Recessed inputs and stats use `bg-surface-container-low` (`#eff4ff`) with zero shadow, reinforcing an engraved tactile tier.

## Shapes
The design embraces a cohesive rounded-corner paradigm configured to level 1 (`roundedness: 1`):

- **Interactive Primary Elements & Inputs:** Buttons, dropdown selectors, and input wrappers leverage `rounded-lg` (8px) and `rounded-xl` (12px).
- **Cards & Bento Panels:** Enclosing project containers and sidebar profile modules use `rounded-xl` (12px).
- **Pills, Avatars & Tags:** Avatars, notification dots, and priority tags use `rounded-full` (9999px) for friendly contrast against rectangular grid modules.
- **Inner Inset Strips:** Task/budget metrics use `rounded-lg` (8px) to fit neatly inside parent cards.

## Components

### Buttons
- **Primary Action:** Solid `bg-primary-container text-on-primary` with `rounded-xl font-title-sm shadow-sm hover:bg-primary transition-all`. Incorporates an inline Material Symbol icon on the left.
- **Secondary / Ghost:** `bg-surface-container-lowest text-on-surface-variant font-label-md rounded-xl shadow-sm hover:bg-surface-container`.
- **View Toggle Buttons:** Grouped within a `bg-surface-container-low p-1 rounded-lg` shell. Active state switches to `bg-surface-container-lowest text-primary shadow-xs`.

### Chips & Badges
- **Status/Priority Chips:** Small pill format (`rounded-full px-2 py-0.5 font-label-sm`).
  - *High:* `bg-error-container text-error`.
  - *Medium:* `bg-tertiary-fixed text-on-tertiary-fixed-variant`.
  - *Low:* `bg-secondary-container text-on-secondary-container`.
  - *Client Tag:* `bg-surface-container text-on-surface-variant`.
- **Category Filter Chips:** `rounded-md px-2 py-0.5 font-label-sm bg-surface-container-low text-primary`.

### Cards & Bento Modules
- **Project Bento Card:** Container styled with `bg-surface-container-lowest rounded-xl p-space-lg shadow-sm hover:shadow-md flex flex-col justify-between`. Houses the header row (badges + overflow menu), title, multi-line description, tag row, continuous gradient progress bar, dual-column inset metric strip, and footer with avatar stack plus due date.
- **KPI Summary Card:** Compact structure featuring a 48x48px circle icon container (`rounded-full bg-surface-container flex items-center justify-center`), metric counter, and trend label.

### Input Fields & Selectors
- **Search Inputs:** Built with `bg-surface-container-low rounded-xl py-space-xs pl-10 pr-12 text-on-surface placeholder:text-outline focus:ring-2 focus:ring-primary-container`. Supports shortcut chips (`⌘ K`) aligned absolutely on the right.
- **Dropdowns:** Custom appearance-none selects in `bg-surface-container-low rounded-lg pl-3 pr-7 py-1.5 font-label-md` accompanied by an absolute `expand_more` icon.

### Progress Bars
- Linear track using `h-2 rounded-full bg-surface-container overflow-hidden`.
- Dynamic fill utilizing horizontal linear gradients:
  - Default: `bg-gradient-to-r from-primary to-primary-container`
  - Warning/Review: `bg-gradient-to-r from-tertiary-container to-tertiary`
  - Success/High Completion: `bg-gradient-to-r from-secondary-container to-secondary`

### Avatar Stacks
- Overlapping circle images sized 28x28px (`w-7 h-7 rounded-full object-cover -space-x-2 ring-2 ring-surface-container-lowest`) with an overflow numeric counter styled as `bg-surface-container font-label-sm font-bold`.