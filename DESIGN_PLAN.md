# ODR Responsive Design Plan

## Overview

This document outlines a CSS-only approach to making the Open Data Repository (ODR) application responsive across phones, tablets, and desktop browsers.

## Current State Assessment

### Site Survey Findings

**Pages Surveyed:**
1. Login page - centered form, minimal layout
2. Admin Dashboard (Database List) - data table with sortable columns
3. RRUFF Samples Search - sidebar + main content layout with record cards
4. Database Admin Dashboard - tools sidebar + related databases table + statistics charts
5. User Management - data table with action buttons
6. Dataset Design Editor - currently shows "Larger Browser Recommended" warning
7. **Individual Record View (Andradite R040001)** - detailed mineral record with multiple sections
8. **Graph Filter Interface** - multi-select filter panel for Raman Spectra graphs

### Individual Record View Analysis

The record view (e.g., `/rruff_sample#/view/640190/...`) contains complex components:

**Header Section:**
- Edit/Choose View buttons
- Prev/Next navigation with "Browse Results Record X of Y"
- Created by/Last Modified metadata

**Main Record Content:**
- Mineral Name + Ideal IMA Formula (with sub/superscripts)
- Sample photo (large image, ~300px)
- RRUFF ID, Sample Locality, Sample Description, Sample Status
- Sample Source/Owned by metadata
- Data Source Citation (collapsible tab)
- Mineral Group links, Quick Search links

**Child Data Sections (collapsible tabs with dropdowns):**
- Chemistry Analyses - with microprobe image and chemistry formula
- Raman Spectra - **interactive Plotly graph** with filter system
- Broad Scan Raman Spectra - additional spectral data
- Infrared Spectra - with downloadable data files
- X-ray Diffraction - crystallographic data with unit cell parameters
- References - extensive bibliography list

### Graph Filter Interface Analysis

When clicking a Raman Spectra graph, a sophisticated filter panel appears:

**Filter Controls (4-column grid on desktop):**
- Child RRUFF ID (multi-select listbox)
- Sample Description (multi-select listbox)
- Exposure Time (s) (multi-select listbox)
- Wavelength (nm) (multi-select listbox)
- Power (mW) (multi-select listbox)
- Accumulations (multi-select listbox)
- Rotation angle from fiducial mark
- Rotation Angle Direction
- Operator (multi-select listbox)
- Raman Data (RAW) Quality
- Raman Data (Processed) Quality
- Pin Label
- Vector X, Y, Z (multiple sets)
- Vector Reference Space

**Key Responsive Challenges:**
- 4-column filter grid needs to collapse to 2-column (tablet) and 1-column (phone)
- Multi-select listboxes need touch-friendly sizing
- Graph toolbar icons need adequate tap targets
- "Select all" links need to remain accessible

**Key Observations:**
- The site already uses Pure CSS framework with responsive grid classes (`pure-u-1`, `pure-u-md-*`, etc.)
- Viewport meta tag is correctly configured in `full.html.twig`
- Media query files exist but are mostly empty scaffolds
- Navigation already has a mobile toggle (`#toggle` hamburger menu)
- Tables use DataTables library which has responsive extensions available

### Current CSS Architecture

```
web/css/
├── odr.1.8.0.css          # Main ODR styles (Pure CSS-based)
├── custom.css              # Custom utility styles
├── pure_layout.css         # Pure CSS layout
├── themes/
│   └── css_smart/
│       ├── smart.1.8.0.css    # Theme entry (imports others)
│       ├── style.1.8.0.css    # Main theme styles
│       ├── media_phone.css    # Phone breakpoints (mostly empty)
│       ├── media_tablet.css   # Tablet breakpoints (mostly empty)
│       └── media_browser.css  # Desktop breakpoints
```

### Breakpoints Strategy

Using modern mobile-first breakpoints:
- **Phone**: < 568px (portrait) / < 768px (landscape)
- **Tablet**: 768px - 1024px
- **Desktop**: > 1024px
- **Large Desktop**: > 1500px (for the design editor)

## Implementation Plan

### Phase 1: Core Layout Responsiveness

**Target Files:** `web/css/themes/css_smart/media_phone.css`, `media_tablet.css`

#### 1.1 Navigation Menu
- [x] Already has hamburger toggle for mobile
- [ ] Ensure dropdown submenus work on touch devices
- [ ] Stack navigation items vertically on phone
- [ ] Adjust font sizes for readability

#### 1.2 Main Content Container
```css
/* In media_phone.css */
@media screen and (max-width: 767px) {
    #odr_content {
        padding: 0 8px;
    }

    #left-spacer, #right-spacer {
        display: none;
    }
}
```

#### 1.3 Search Sidebar (RRUFF Database pages)
- Convert sidebar to collapsible accordion on mobile
- Stack sidebar above results on phone
- Side-by-side on tablet (narrower sidebar)

### Phase 2: Data Tables

**Target:** DataTables instances throughout the application

#### 2.1 Database List Table
```css
@media screen and (max-width: 767px) {
    /* Hide less essential columns on mobile */
    .dataTable th:nth-child(6),
    .dataTable td:nth-child(6),
    .dataTable th:nth-child(7),
    .dataTable td:nth-child(7) {
        display: none; /* Hide Created On, Description */
    }

    /* Make table horizontally scrollable */
    .dataTables_wrapper {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
}
```

#### 2.2 User List Table
- Hide Institution column on phone
- Stack action buttons vertically

### Phase 3: Record Display Cards

**Target:** `.ODRThemeElement`, `.ODRDataField`, record display areas

#### 3.1 Record Cards in Search Results
```css
@media screen and (max-width: 767px) {
    /* Stack all record content vertically */
    .ODRFieldArea .pure-u-md-1-2,
    .ODRFieldArea .pure-u-md-1-3,
    .ODRFieldArea .pure-u-md-1-4 {
        width: 100%;
    }

    /* Images responsive */
    .ODRImageGallery img,
    .ODRImage img {
        max-width: 100%;
        height: auto;
    }

    /* Gallery navigation adjustments */
    .ODRGalleryLeftArrow,
    .ODRGalleryRightArrow {
        padding: 0.5em;
    }
}
```

#### 3.2 Mineral Name / Formula Display
- Ensure chemical formulas wrap properly
- Adjust font sizes for mobile readability

### Phase 4: Admin Dashboard

**Target:** Database admin landing pages, statistics dashboard

#### 4.1 Database Tools Sidebar
```css
@media screen and (max-width: 1024px) {
    /* Stack tools list and main content */
    .ODRDatabaseTools {
        width: 100%;
        margin-bottom: 1em;
    }
}
```

#### 4.2 Statistics Cards
```css
@media screen and (max-width: 767px) {
    /* Stack stat cards */
    .statistics-card {
        width: 50%;
    }
}

@media screen and (max-width: 480px) {
    .statistics-card {
        width: 100%;
    }
}
```

#### 4.3 Charts (Plotly)
- Charts should auto-resize (Plotly has built-in responsiveness)
- May need to add `responsive: true` config
- Geographic map: ensure touch-friendly

### Phase 5: Forms

**Target:** Login, profile edit, search forms

#### 5.1 Login Form
- Already centered and reasonably responsive
- Ensure input fields are full-width on mobile
- Touch-friendly button sizes (min 44px tap target)

#### 5.2 Search Forms
```css
@media screen and (max-width: 767px) {
    .pure-form input[type="text"],
    .pure-form select {
        width: 100%;
        margin: 0.5em 0;
    }

    .pure-button {
        width: 100%;
        margin: 0.5em 0;
    }
}
```

### Phase 6: Individual Record View

**Target:** Record detail pages with spectra, crystallography, and references

#### 6.1 Record Header & Navigation
```css
@media screen and (max-width: 767px) {
    /* Stack record metadata vertically */
    .ODRRecordHeader {
        flex-direction: column;
    }

    /* Full-width Prev/Next buttons */
    .ODRBrowseResults {
        width: 100%;
        justify-content: space-between;
    }

    /* Edit/Choose View buttons - stack or make smaller */
    .ODRRecordActions button {
        padding: 8px 12px;
        font-size: 14px;
    }
}
```

#### 6.2 Record Content Layout
```css
@media screen and (max-width: 767px) {
    /* Sample photo - responsive */
    .ODRSamplePhoto img {
        max-width: 100%;
        height: auto;
    }

    /* Stack metadata fields */
    .ODRFieldArea .pure-u-1-2,
    .ODRFieldArea .pure-u-1-3 {
        width: 100%;
    }

    /* Chemical formulas - allow wrapping */
    .ODRFormulaDisplay {
        word-wrap: break-word;
        overflow-wrap: break-word;
    }
}
```

#### 6.3 Collapsible Tab Sections
```css
@media screen and (max-width: 767px) {
    /* Tab headers - full width */
    .ODRCollapsibleTab {
        display: block;
        width: 100%;
    }

    /* Dropdown selectors within tabs */
    .ODRCollapsibleTab select {
        max-width: 150px;
    }
}
```

#### 6.4 References List
```css
@media screen and (max-width: 767px) {
    /* Reference text - smaller font, better wrapping */
    .ODRReferences {
        font-size: 0.85em;
        line-height: 1.5;
    }

    .ODRReferences a {
        word-break: break-all;
    }
}
```

### Phase 7: Graph Filter Interface

**Target:** Multi-select filter panels for Raman Spectra and other graphs

#### 7.1 Filter Grid Layout
```css
@media screen and (max-width: 1024px) {
    /* 4-column to 2-column on tablet */
    .ODRGraphFilterGrid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media screen and (max-width: 767px) {
    /* 2-column to 1-column on phone */
    .ODRGraphFilterGrid {
        grid-template-columns: 1fr;
    }

    /* Each filter group full width */
    .ODRGraphFilterGroup {
        width: 100%;
        margin-bottom: 1em;
    }
}
```

#### 7.2 Multi-Select Listboxes
```css
@media screen and (max-width: 767px) {
    /* Make listboxes taller for touch */
    .ODRGraphFilterGroup select[multiple] {
        min-height: 120px;
        font-size: 16px; /* Prevent iOS zoom */
    }

    /* Select all link - larger tap target */
    .ODRSelectAllLink {
        display: inline-block;
        padding: 8px;
        min-height: 44px;
        line-height: 28px;
    }
}
```

#### 7.3 Graph Container
```css
@media screen and (max-width: 767px) {
    /* Plotly graph - allow horizontal scroll if needed */
    .ODRGraphContainer {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }

    /* Plotly modebar - ensure visibility */
    .plotly .modebar {
        transform: scale(1.2);
        transform-origin: top right;
    }
}
```

#### 7.4 Download Link
```css
@media screen and (max-width: 767px) {
    /* Download all files link - prominent button style */
    .ODRGraphDownloadLink {
        display: block;
        width: 100%;
        padding: 12px;
        text-align: center;
        background-color: #EEEEFF;
        border-radius: 4px;
        margin: 1em 0;
    }
}
```

### Phase 8: Design Editor

**Target:** Dataset design interface (currently desktop-only)

#### 6.1 Approach
- Keep desktop-only recommendation for now
- Provide clear message on mobile devices
- Already has `#ThemeDesignWrapperMessage` for this purpose

```css
@media screen and (max-width: 1200px) {
    #ThemeDesignWrapperMessage {
        display: block;
    }
    #ThemeDesignWrapper {
        display: none;
    }
}
```

## CSS Files to Modify

### Primary Target: `web/css/themes/css_smart/media_phone.css`

This file will contain the bulk of mobile-specific styles using modern breakpoints:

```css
/* Mobile First - Base styles for phones */
@media screen and (max-width: 767px) {
    /* Navigation */
    /* Content containers */
    /* Tables */
    /* Record cards */
    /* Forms */
    /* Images */
}
```

### Secondary Target: `web/css/themes/css_smart/media_tablet.css`

```css
/* Tablet styles */
@media screen and (min-width: 768px) and (max-width: 1024px) {
    /* Sidebar widths */
    /* Table adjustments */
    /* Two-column layouts */
}
```

### Tertiary Target: `web/css/themes/css_smart/media_browser.css`

```css
/* Desktop enhancements */
@media screen and (min-width: 1025px) {
    /* Full-width layouts */
    /* Multi-column record displays */
}
```

## Utility Classes to Add

```css
/* Responsive visibility utilities */
.hidden-phone { }
.hidden-tablet { }
.hidden-desktop { }
.visible-phone-only { }
.visible-tablet-only { }
.visible-desktop-only { }

/* Responsive spacing */
.mt-mobile-1 { } /* margin-top on mobile */
.p-mobile-0 { }  /* no padding on mobile */

/* Touch-friendly elements */
.touch-target {
    min-height: 44px;
    min-width: 44px;
}
```

## Testing Plan

1. **Phone Testing:**
   - iPhone SE (320px width)
   - iPhone 14 (390px width)
   - Android phone (360px typical)

2. **Tablet Testing:**
   - iPad (768px / 1024px)
   - iPad Pro (1024px / 1366px)

3. **Browser Testing:**
   - Chrome DevTools device emulation
   - Firefox Responsive Design Mode
   - Safari on actual iOS devices if possible

## Implementation Order

1. **Week 1:** Core layout (navigation, containers, spacing)
2. **Week 2:** Data tables responsiveness
3. **Week 3:** Record cards and search results
4. **Week 4:** Admin dashboards and forms
5. **Week 5:** Testing and refinement

## Notes

- All changes will be CSS-only (no HTML/Twig modifications)
- Leverage existing Pure CSS responsive grid classes where possible
- Maintain backwards compatibility with existing desktop layout
- Use progressive enhancement approach (mobile-first where feasible)
- Avoid `!important` unless overriding third-party library styles

## References

- Pure CSS Responsive Grids: https://purecss.io/grids/
- DataTables Responsive: https://datatables.net/extensions/responsive/
- Plotly Responsive: https://plotly.com/javascript/responsive-fluid-layout/
