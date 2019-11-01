import Handsontable from 'handsontable';

const elem = document.createElement('div');
const hot = new Handsontable(elem, {});

const cellProperties: Handsontable.CellProperties = { 
  row: 0,
  col: 0,
  instance: {} as Handsontable,
  visualRow: 0,
  visualCol: 0,
  prop: 'foo'
};

const TD = document.createElement('td');

// Verify the built-in cellTypes exist and have the correct shape
new Handsontable.cellTypes.autocomplete.editor(hot, 0, 0, 'prop', TD, cellProperties);
Handsontable.cellTypes.autocomplete.renderer(hot, TD, 0, 0, 'prop', 'foo', cellProperties);
Handsontable.cellTypes.autocomplete.validator.apply(cellProperties, ['', (valid: boolean) => {}]);

new Handsontable.cellTypes.checkbox.editor(hot, 0, 0, 'prop', TD, cellProperties);
Handsontable.cellTypes.checkbox.renderer(hot, TD, 0, 0, 'prop', 'foo', cellProperties);

new Handsontable.cellTypes.date.editor(hot, 0, 0, 'prop', TD, cellProperties);
Handsontable.cellTypes.date.renderer(hot, TD, 0, 0, 'prop', 'foo', cellProperties);
Handsontable.cellTypes.date.validator.apply(cellProperties, ['', (valid: boolean) => {}]);

new Handsontable.cellTypes.dropdown.editor(hot, 0, 0, 'prop', TD, cellProperties);
Handsontable.cellTypes.dropdown.renderer(hot, TD, 0, 0, 'prop', 'foo', cellProperties);
Handsontable.cellTypes.dropdown.validator.apply(cellProperties, ['', (valid: boolean) => {}]);

new Handsontable.cellTypes.handsontable.editor(hot, 0, 0, 'prop', TD, cellProperties);
Handsontable.cellTypes.handsontable.renderer(hot, TD, 0, 0, 'prop', 'foo', cellProperties);

new Handsontable.cellTypes.numeric.editor(hot, 0, 0, 'prop', TD, cellProperties);
Handsontable.cellTypes.numeric.renderer(hot, TD, 0, 0, 'prop', 'foo', cellProperties);
Handsontable.cellTypes.numeric.validator.apply(cellProperties, ['', (valid: boolean) => {}]);

new Handsontable.cellTypes.password.editor(hot, 0, 0, 'prop', TD, cellProperties);
Handsontable.cellTypes.password.renderer(hot, TD, 0, 0, 'prop', 'foo', cellProperties);

new Handsontable.cellTypes.text.editor(hot, 0, 0, 'prop', TD, cellProperties);
Handsontable.cellTypes.text.renderer(hot, TD, 0, 0, 'prop', 'foo', cellProperties);

new Handsontable.cellTypes.time.editor(hot, 0, 0, 'prop', TD, cellProperties);
Handsontable.cellTypes.time.renderer(hot, TD, 0, 0, 'prop', 'foo', cellProperties);
Handsontable.cellTypes.time.validator.apply(cellProperties, ['', (valid: boolean) => {}]);

// Verify top-level cellTypes API
const autocomplete = Handsontable.cellTypes.getCellType('autocomplete') as Handsontable.cellTypes.Autocomplete;
new autocomplete.editor(hot, 0, 0, 'prop', TD, cellProperties);
autocomplete.renderer(hot, TD, 0, 0, 'prop', 'foo', cellProperties);
autocomplete.validator.apply(cellProperties, ['', (valid: boolean) => {}]);

class CustomEditor extends Handsontable.editors.BaseEditor {
  open(){}
  close(){}
  getValue(){}
  setValue(value: any){}
  focus(){}
}
Handsontable.cellTypes.registerCellType('custom', {
  editor: CustomEditor,
  renderer: (hot: Handsontable, TD: HTMLTableCellElement, row: number, col: number, prop: number | string, value: any, cellProperties: Handsontable.CellProperties) => TD,
  validator: (value: any, callback: (valid: boolean) => void) => {},
  className: 'my-cell',
  allowInvalid: true,
  myCustomCellState: 'complete',
});
