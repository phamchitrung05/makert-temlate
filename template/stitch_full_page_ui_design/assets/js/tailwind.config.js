/**
 * FlowTask - Vibrant SaaS Productivity Tailwind CSS Configuration
 */
tailwind.config = {
  darkMode: "class",
  theme: {
    extend: {
      colors: {
        "on-secondary-fixed-variant": "#005236",
        "surface-container-low": "#eff4ff",
        "on-tertiary-container": "#ffeedd",
        "inverse-primary": "#b4c5ff",
        "secondary": "#006c49",
        "surface": "#f8f9ff",
        "tertiary-container": "#996100",
        "on-secondary-fixed": "#002113",
        "on-secondary": "#ffffff",
        "on-primary": "#ffffff",
        "surface-container-highest": "#d3e4fe",
        "error-container": "#ffdad6",
        "surface-bright": "#f8f9ff",
        "error": "#ba1a1a",
        "secondary-container": "#6cf8bb",
        "on-surface-variant": "#434655",
        "surface-variant": "#d3e4fe",
        "primary-container": "#2563eb",
        "inverse-on-surface": "#eaf1ff",
        "on-error": "#ffffff",
        "surface-container-lowest": "#ffffff",
        "on-tertiary-fixed": "#2a1700",
        "on-primary-fixed-variant": "#003ea8",
        "on-tertiary": "#ffffff",
        "primary-fixed": "#dbe1ff",
        "outline-variant": "#c3c6d7",
        "surface-container-high": "#dce9ff",
        "secondary-fixed-dim": "#4edea3",
        "on-primary-container": "#eeefff",
        "background": "#f8f9ff",
        "inverse-surface": "#213145",
        "secondary-fixed": "#6ffbbe",
        "outline": "#737686",
        "on-background": "#0b1c30",
        "on-secondary-container": "#00714d",
        "tertiary": "#784b00",
        "primary": "#004ac6",
        "primary-fixed-dim": "#b4c5ff",
        "on-surface": "#0b1c30",
        "tertiary-fixed": "#ffddb8",
        "on-tertiary-fixed-variant": "#653e00",
        "surface-tint": "#0053db",
        "on-error-container": "#93000a",
        "tertiary-fixed-dim": "#ffb95f",
        "on-primary-fixed": "#00174b",
        "surface-dim": "#cbdbf5",
        "surface-container": "#e5eeff"
      },
      borderRadius: {
        "DEFAULT": "0.25rem",
        "lg": "0.5rem",
        "xl": "0.75rem",
        "2xl": "1rem",
        "3xl": "1.5rem",
        "full": "9999px"
      },
      spacing: {
        "space-xl": "2rem",
        "margin": "1.5rem",
        "space-sm": "0.5rem",
        "space-md": "1rem",
        "space-xs": "0.25rem",
        "space-lg": "1.5rem",
        "gutter": "1.25rem"
      },
      fontFamily: {
        "body-sm": ["Inter", "sans-serif"],
        "headline-md": ["Plus Jakarta Sans", "sans-serif"],
        "label-sm": ["Inter", "sans-serif"],
        "body-md": ["Inter", "sans-serif"],
        "headline-lg-mobile": ["Plus Jakarta Sans", "sans-serif"],
        "headline-lg": ["Plus Jakarta Sans", "sans-serif"],
        "label-md": ["Inter", "sans-serif"],
        "title-sm": ["Plus Jakarta Sans", "sans-serif"],
        "stat-metric": ["Plus Jakarta Sans", "sans-serif"],
        "display-hero": ["Plus Jakarta Sans", "sans-serif"]
      },
      fontSize: {
        "body-sm": ["13px", { lineHeight: "18px", fontWeight: "400" }],
        "headline-md": ["20px", { lineHeight: "28px", fontWeight: "700" }],
        "label-sm": ["11px", { lineHeight: "14px", fontWeight: "600" }],
        "body-md": ["14px", { lineHeight: "20px", fontWeight: "400" }],
        "headline-lg-mobile": ["24px", { lineHeight: "32px", fontWeight: "700" }],
        "headline-lg": ["32px", { lineHeight: "40px", fontWeight: "700" }],
        "label-md": ["12px", { lineHeight: "16px", fontWeight: "500" }],
        "title-sm": ["16px", { lineHeight: "24px", fontWeight: "600" }],
        "stat-metric": ["28px", { lineHeight: "32px", fontWeight: "800" }],
        "display-hero": ["56px", { lineHeight: "64px", fontWeight: "800" }]
      }
    }
  }
};
