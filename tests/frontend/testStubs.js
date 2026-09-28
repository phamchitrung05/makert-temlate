export const Passthrough = {
  template: '<div><slot /></div>',
}

export const passthroughStubs = names => Object.fromEntries(
  names.map(name => [name, Passthrough]),
)
