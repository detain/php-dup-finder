// Unrelated helper: temperature conversion utilities.
export function celsiusToFahrenheit(degrees) {
  if (typeof degrees !== 'number') {
    throw new TypeError('expected a numeric temperature');
  }
  return degrees * 1.8 + 32;
}

export function formatReading(raw) {
  const rounded = Math.round(celsiusToFahrenheit(raw) * 10) / 10;
  return `${rounded}F measured`;
}

export const ZERO_KELVIN_OFFSET = -273.15;
