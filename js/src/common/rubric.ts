import app from 'flarum/common/app';

declare const flarum: any;

/** A Rubric prefix as the forum payload ships it. */
export interface RubricPrefix {
  id: number;
  name: string;
  color: string;
  icon: string | null;
  tagIds: number[];
}

/** Rubric's prefixes, or null when Rubric is not installed and enabled. */
export function rubricPrefixes(): RubricPrefix[] | null {
  const list = app.forum && app.forum.attribute<RubricPrefix[]>('rubricPrefixes');
  const enabled = typeof flarum !== 'undefined' && !!flarum.extensions && 'ernestdefoe-rubric' in flarum.extensions;

  return enabled && Array.isArray(list) ? list : null;
}

export function prefixName(id: number): string {
  const p = (rubricPrefixes() || []).find((x) => Number(x.id) === Number(id));
  return p ? p.name : '#' + id;
}

/** Dark or light text for a label colour (WCAG luminance), as Rubric draws it. */
export function textColorFor(hex: string): string {
  const match = /^#?([0-9a-f]{6})$/i.exec(hex || '');
  if (!match) return '#fff';

  const n = parseInt(match[1], 16);
  const channel = (c: number) => {
    const s = c / 255;
    return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
  };
  const L = 0.2126 * channel((n >> 16) & 255) + 0.7152 * channel((n >> 8) & 255) + 0.0722 * channel(n & 255);

  return L > 0.179 ? '#111827' : '#fff';
}
