# MeshSilo Mobile Responsiveness Testing Checklist

This document provides a comprehensive guide for testing MeshSilo's mobile responsiveness across different devices and screen sizes.

## Breakpoints Used

MeshSilo uses a mobile-first approach with the following breakpoints defined in `assets/css/responsive.css`:

| Breakpoint | Width | Target Devices |
|------------|-------|----------------|
| Base | < 640px | Mobile phones (portrait) |
| `sm` | >= 640px | Mobile phones (landscape), small tablets |
| `md` | >= 768px | Tablets (portrait) |
| `lg` | >= 1024px | Tablets (landscape), small laptops |
| `xl` | >= 1280px | Desktops, laptops |
| `2xl` | >= 1536px | Large desktops, wide monitors |

### Additional Media Queries

| Query | Purpose |
|-------|---------|
| `(hover: none) and (pointer: coarse)` | Touch device enhancements |
| `(prefers-reduced-motion: reduce)` | Accessibility - reduced animations |
| `(prefers-color-scheme: dark)` | Dark mode support |
| `print` | Print styles |

---

## Components to Test on Mobile

### Navigation

| Component | File | Mobile Behavior |
|-----------|------|-----------------|
| Sidebar | `responsive.css` | Slides in from left, overlay background |
| Mobile menu toggle | `responsive.js` | FAB button (bottom-right), hamburger icon |
| Sidebar overlay | `responsive.js` | Tap to close sidebar |

**Test Cases:**
- [ ] Sidebar opens on menu button tap
- [ ] Sidebar closes on overlay tap
- [ ] Sidebar closes on Escape key
- [ ] Swipe right from left edge opens sidebar
- [ ] Swipe left closes sidebar
- [ ] Menu button changes to X when open
- [ ] Sidebar auto-closes when resizing to desktop

### Model Grid

| Breakpoint | Columns |
|------------|---------|
| < 640px | 2 columns |
| >= 640px | 3 columns |
| >= 768px | 3 columns |
| >= 1024px | 4 columns |
| >= 1280px | 5 columns |
| >= 1536px | 6 columns |

**Test Cases:**
- [ ] Grid resizes correctly at each breakpoint
- [ ] Card images maintain aspect ratio
- [ ] Card titles truncate with ellipsis
- [ ] Touch targets are at least 44x44px

### Model Cards

**Test Cases:**
- [ ] Card image loads with 1:1 aspect ratio on mobile
- [ ] Card body padding reduced on mobile (0.75rem)
- [ ] Card title font size reduced (0.875rem)
- [ ] Card meta text readable (0.75rem)
- [ ] Tap on card navigates to model detail
- [ ] Long model names truncate properly

### Tables (Responsive)

Tables with class `.responsive-table` convert to card layout on mobile.

**Test Cases:**
- [ ] Table headers hidden on mobile (< 768px)
- [ ] Each row becomes a card
- [ ] Data labels appear via `::before` pseudo-element
- [ ] Card shadows and spacing correct
- [ ] Table returns to normal layout on desktop

### Forms

**Test Cases:**
- [ ] Form rows stack vertically on mobile
- [ ] Input fields span full width
- [ ] Input font size is 16px (prevents iOS zoom)
- [ ] Button groups wrap properly
- [ ] Touch targets are minimum 44px height
- [ ] Form validation messages visible

### Modals

**Test Cases:**
- [ ] Modal takes full width on mobile
- [ ] Modal content scrollable
- [ ] Modal max-height is 90vh
- [ ] Close button easily tappable
- [ ] Modal header/footer padding correct

### Filter Panel

**Test Cases:**
- [ ] Filter panel slides up from bottom on mobile
- [ ] Filter toggle button visible on mobile
- [ ] Panel closes on close button tap
- [ ] Panel respects max-height (70vh)
- [ ] Panel scrollable when content overflows
- [ ] Panel hidden by default, visible on desktop

### Pagination

**Test Cases:**
- [ ] Pagination wraps on narrow screens
- [ ] Page links have adequate tap targets (40px min-width)
- [ ] Current page indicator visible
- [ ] Previous/Next buttons accessible

### 3D Viewer

**Test Cases:**
- [ ] Viewer container responsive
- [ ] Touch controls work (pinch to zoom, drag to rotate)
- [ ] Viewer toolbar accessible
- [ ] Performance acceptable on mobile devices
- [ ] Large models may need warning message

### Model Detail Page

**Test Cases:**
- [ ] Header stacks vertically on mobile
- [ ] Preview takes full width, max-height 300px
- [ ] Info section takes full width
- [ ] Action buttons stack vertically
- [ ] Action buttons take full width
- [ ] Tabs horizontally scrollable

### Statistics Cards

**Test Cases:**
- [ ] Stats grid: 2 columns on mobile, 4 on tablet+
- [ ] Stat values readable (1.5rem)
- [ ] Card padding adequate (1rem)

### Upload Area

**Test Cases:**
- [ ] Dropzone padding reduced on mobile
- [ ] Instructions text smaller (0.875rem)
- [ ] File selection works via tap
- [ ] Upload progress visible

---

## Known Mobile Issues

### Current Issues

| Issue | Severity | Workaround | Component |
|-------|----------|------------|-----------|
| Large 3D models may cause browser crash | Medium | Add file size warning | 3D Viewer |
| iOS Safari address bar affects vh units | Low | Use `dvh` units where supported | Modals, Filter panel |
| Horizontal scroll on some pages | Medium | Check for elements exceeding viewport | Various |
| Keyboard pushes content on iOS | Low | Use `visualViewport` API | Forms |

### Browser-Specific Issues

| Browser | Issue | Status |
|---------|-------|--------|
| Safari iOS | 100vh includes address bar | Use CSS `dvh` or JS workaround |
| Safari iOS | Form zoom when font < 16px | Font size set to 16px for inputs |
| Chrome Android | Address bar color | Set via manifest.json |
| All mobile | Hover states persist after tap | Disabled hover effects for touch |

---

## Testing Procedure

### 1. Device Testing Matrix

Test on these device categories at minimum:

| Category | Example Devices | Screen Widths |
|----------|-----------------|---------------|
| Small phone | iPhone SE, Galaxy A | 320px - 375px |
| Standard phone | iPhone 14, Pixel 7 | 375px - 414px |
| Large phone | iPhone 14 Pro Max, Galaxy S Ultra | 414px - 480px |
| Small tablet | iPad Mini | 768px |
| Large tablet | iPad Pro | 1024px - 1366px |

### 2. Browser Testing

Test on these mobile browsers:
- [ ] Safari (iOS)
- [ ] Chrome (Android)
- [ ] Firefox (Android)
- [ ] Samsung Internet
- [ ] Edge (mobile)

### 3. Testing Steps

#### A. Viewport Testing (Browser DevTools)

1. Open Chrome/Firefox DevTools (F12)
2. Toggle device toolbar (Ctrl+Shift+M)
3. Test at each breakpoint width:
   - 320px, 375px, 414px, 480px
   - 640px, 768px
   - 1024px, 1280px, 1536px

4. For each page, verify:
   - [ ] No horizontal scrollbar
   - [ ] Content readable without zooming
   - [ ] Interactive elements tappable
   - [ ] Images scale appropriately
   - [ ] Text doesn't overflow containers

#### B. Real Device Testing

1. Connect device or use remote debugging
2. Navigate through all main pages:
   - [ ] Home/Browse page
   - [ ] Model detail page
   - [ ] Upload page
   - [ ] Search results
   - [ ] User profile
   - [ ] Admin pages (if applicable)

3. Test interactions:
   - [ ] Sidebar navigation
   - [ ] Form submission
   - [ ] 3D viewer controls
   - [ ] File upload
   - [ ] Modal dialogs

#### C. Orientation Testing

1. Test in portrait mode
2. Rotate to landscape
3. Verify:
   - [ ] Layout adapts properly
   - [ ] No content cut off
   - [ ] Scrolling works correctly
   - [ ] Modals/overlays resize

#### D. Touch Interaction Testing

1. Verify touch targets (minimum 44x44px)
2. Test swipe gestures:
   - [ ] Sidebar open/close
   - [ ] Tab navigation (if scrollable)
   - [ ] 3D model rotation/zoom
3. Test long-press behaviors (if any)
4. Verify no accidental taps from large hit areas

#### E. Performance Testing

1. Use Chrome DevTools Performance tab
2. Throttle to "Mid-tier mobile" CPU
3. Check:
   - [ ] Page loads under 3 seconds on 3G
   - [ ] Animations smooth (60fps)
   - [ ] No layout thrashing
   - [ ] Images lazy-load properly

#### F. Accessibility Testing

1. Test with larger text sizes (200%)
2. Verify color contrast in both themes
3. Test with screen reader (VoiceOver/TalkBack)
4. Check focus indicators visible

---

## Test Report Template

```markdown
## Mobile Testing Report

**Date:** YYYY-MM-DD
**Tester:** Name
**Version:** X.Y.Z
**Devices Tested:** iPhone 14 (iOS 17), Pixel 7 (Android 14)

### Summary

| Category | Pass | Fail | Notes |
|----------|------|------|-------|
| Navigation | 5 | 0 | |
| Model Grid | 4 | 1 | Issue #123 |
| Forms | 6 | 0 | |
| 3D Viewer | 3 | 1 | Performance on large models |
| ... | | | |

### Issues Found

#### Issue 1: [Title]
- **Device:** iPhone 14
- **Browser:** Safari
- **Steps to Reproduce:**
  1. Step one
  2. Step two
- **Expected:** Description
- **Actual:** Description
- **Screenshot:** [link]
- **Severity:** High/Medium/Low

### Recommendations

1. Recommendation one
2. Recommendation two
```

---

## Utility Classes for Responsive Development

These utility classes are available in `responsive.css`:

| Class | Behavior |
|-------|----------|
| `.hide-mobile` | Hidden below 768px |
| `.show-mobile` | Shown only below 768px |
| `.hide-tablet` | Hidden between 768px and 1023px |
| `.hide-desktop` | Hidden above 1024px |
| `.flex-col-mobile` | Column on mobile, row on desktop |
| `.gap-responsive` | 0.75rem -> 1rem -> 1.5rem |
| `.text-truncate-mobile` | Truncates text on mobile only |
| `.no-print` | Hidden when printing |

---

## Resources

- [Chrome DevTools Device Mode](https://developer.chrome.com/docs/devtools/device-mode/)
- [Safari Web Inspector](https://developer.apple.com/documentation/safari-developer-tools)
- [BrowserStack](https://www.browserstack.com/) - Real device testing
- [Responsively App](https://responsively.app/) - Multi-device preview
- [WCAG Touch Target Guidelines](https://www.w3.org/WAI/WCAG21/Understanding/target-size.html)
