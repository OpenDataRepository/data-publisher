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

// Verify the built-in renderers exist and have the correct signature
Handsontable.renderers.AutocompleteRenderer(hot, new HTMLTableDataCellElement(), 0, 0, 'prop', 1.235, cellProperties);
Handsontable.renderers.BaseRenderer(hot, new HTMLTableDataCellElement(), 0, 0, 'prop', 1.235, cellProperties);
Handsontable.renderers.CheckboxRenderer(hot, new HTMLTableDataCellElement(), 0, 0, 'prop', 1.235, cellProperties);
Handsontable.renderers.HtmlRenderer(hot, new HTMLTableDataCellElement(), 0, 0, 'prop', 1.235, cellProperties);
Handsontable.renderers.NumericRenderer(hot, new HTMLTableDataCellElement(), 0, 0, 'prop', 1.235, cellProperties);
Handsontable.renderers.PasswordRenderer(hot, new HTMLTableDataCellElement(), 0, 0, 'prop', 1.235, cellProperties);
Handsontable.renderers.TextRenderer(hot, new HTMLTableDataCellElement(), 0, 0, 'prop', 1.235, cellProperties);

// Verify top-level renderers API
Handsontable.renderers.getRenderer('foo')(hot, TD, 0, 0, 'prop', 1.235, {} as Handsontable.CellProperties);
Handsontable.renderers.registerRenderer('foo', (hot: Handsontable, TD: HTMLTableCellElement, row: number, col: number, prop: string | number, value: any, cellProperties: Handsontable.CellProperties) => TD)
