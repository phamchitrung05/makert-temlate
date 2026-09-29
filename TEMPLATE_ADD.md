# TEMPLATE_ADD

## Canonical admin Add/Edit page structure

When creating or restyling an admin Add/Edit page, use
`resources/js/pages/apps/ecommerce/product/add/index.vue` as the visual source of
truth. New forms should look like this page unless the user explicitly provides a
different design.

### Page and component responsibilities

- Keep the route page thin: read route parameters, connect the Pinia store, call
  create/update actions, and navigate after success.
- Put form state and submit validation in a feature form component.
- Split substantial content, media, and settings sections into focused child
  components. Use props down and events or named `v-model` bindings up.
- Only include backend-supported fields in the submitted payload. An editable
  preview-only field must be clearly marked `Planned` until persistence exists.
- Every touched `.vue` file must retain the Vietnamese structured header comment
  that describes its purpose, important functions, and input/output contract.

### Header

Use the same hierarchy and spacing as Product Add:

```vue
<div class="d-flex flex-wrap justify-start justify-sm-space-between gap-y-4 gap-x-6 mb-6">
  <div class="d-flex flex-column justify-center">
    <h4 class="text-h4 font-weight-medium">
      Page title
    </h4>
    <div class="text-body-1">
      Short page description
    </div>
  </div>

  <div class="d-flex gap-4 align-center flex-wrap">
    <!-- Discard, Save Draft, Publish -->
  </div>
</div>
```

### Main layout

- Use `VRow` with a primary `VCol md="8"` and a settings `VCol md="4"`.
- Add `cols="12"` when an explicit mobile width improves readability.
- Use Vuexy/Vuetify spacing utilities; the standard gap between cards is `mb-6`.

### Cards

Use the same default `VCard` appearance as Product Add. Do not add custom
`rounded="xl"`, `elevation="0"`, `border`, title icons, or forced card-title
colors unless the requested design specifically requires them.

Simple card:

```vue
<VCard
  title="Section title"
  class="mb-6"
>
  <VCardText>
    <!-- fields -->
  </VCardText>
</VCard>
```

Card with an action, badge, or secondary control in the header:

```vue
<VCard class="mb-6">
  <VCardItem>
    <template #title>
      Section title
    </template>
    <template #append>
      <!-- action, badge, or control -->
    </template>
  </VCardItem>

  <VCardText>
    <!-- fields -->
  </VCardText>
</VCard>
```

Use `AppCardActions` only when collapse, refresh, or remove behavior is a real
requirement. Do not use it as the default form card.

### Typography and form controls

- Let the Vuexy theme provide card-title and label colors and font sizes. Avoid
  page-specific CSS that forces labels or titles to black; this must continue to
  work in dark mode.
- Prefer `AppTextField`, `AppTextarea`, `AppSelect`, `AppCombobox`, and
  `AppDateTimePicker` over their raw equivalents when the wrapper exists.
- Put labels on the App field through its `label` prop. Use `VLabel` only for a
  composed control that cannot receive a normal label.
- Use `ProductDescriptionEditor` with `class="border rounded"` for rich-text
  content when matching the Product Add experience.
- Use `MediaAssetField` for domain media selection. It is the real Media Library
  integration and should not be replaced by the demo `DropZone`.

### Verification checklist

- Run focused ESLint for every touched Vue file.
- Run `npm run test:run`.
- Run `npm run build`.
- Confirm the page remains responsive at the `md` breakpoint and that labels,
  cards, and editor styling match Product Add in both supported themes.
