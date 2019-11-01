import { isEmpty } from './../helpers/mixed';

/**
 * Verifies if node is an HTMLTable element.
 *
 * @param {Node} element Node to verify if it's an HTMLTable.
 * @returns {Boolean}
 */
function isHTMLTable(element) {
  return (element && element.nodeName || '') === 'TABLE';
}

/**
 * Converts Handsontable into HTMLTableElement.
 *
 * @param {Core} instance
 * @returns {String} outerHTML of the HTMLTableElement
 */
export function instanceToHTML(instance) {
  const hasColumnHeaders = instance.hasColHeaders();
  const hasRowHeaders = instance.hasRowHeaders();
  const coords = [
    hasColumnHeaders ? -1 : 0,
    hasRowHeaders ? -1 : 0,
    instance.countRows() - 1,
    instance.countCols() - 1,
  ];
  const data = instance.getData(...coords);
  const countRows = data.length;
  const countCols = countRows > 0 ? data[0].length : 0;
  const TABLE = ['<table>', '</table>'];
  const THEAD = hasColumnHeaders ? ['<thead>', '</thead>'] : [];
  const TBODY = ['<tbody>', '</tbody>'];
  const rowModifier = hasRowHeaders ? 1 : 0;
  const columnModifier = hasColumnHeaders ? 1 : 0;

  for (let row = 0; row < countRows; row += 1) {
    const isColumnHeadersRow = hasColumnHeaders && row === 0;
    const CELLS = [];

    for (let column = 0; column < countCols; column += 1) {
      const isRowHeadersColumn = !isColumnHeadersRow && hasRowHeaders && column === 0;
      let cell = '';

      if (isColumnHeadersRow) {
        cell = `<th>${instance.getColHeader(column - rowModifier)}</th>`;

      } else if (isRowHeadersColumn) {
        cell = `<th>${instance.getRowHeader(row - columnModifier)}</th>`;

      } else {
        const cellData = data[row][column];
        const { hidden, rowspan, colspan } = instance.getCellMeta(row - rowModifier, column - columnModifier);

        if (!hidden) {
          const attrs = [];

          if (rowspan) {
            attrs.push(`rowspan="${rowspan}"`);
          }
          if (colspan) {
            attrs.push(`colspan="${colspan}"`);
          }
          if (isEmpty(cellData)) {
            cell = `<td ${attrs.join(' ')}></td>`;
          } else {
            const value = cellData.toString()
              .replace(/(<br(\s*|\/)>(\r\n|\n)?|\r\n|\n)/g, '<br>\r\n')
              .replace(/\x20/gi, '&nbsp;')
              .replace(/\t/gi, '&#9;');
            cell = `<td ${attrs.join(' ')}>${value}</td>`;
          }
        }
      }

      CELLS.push(cell);
    }

    const TR = ['<tr>', ...CELLS, '</tr>'].join('');

    if (isColumnHeadersRow) {
      THEAD.splice(1, 0, TR);
    } else {
      TBODY.splice(-1, 0, TR);
    }
  }

  TABLE.splice(1, 0, THEAD.join(''), TBODY.join(''));

  return TABLE.join('');
}

/**
 * Converts 2D array into HTMLTableElement.
 *
 * @param {Array} input Input array which will be converted to HTMLTable
 * @returns {String} outerHTML of the HTMLTableElement
 */
// eslint-disable-next-line no-restricted-globals
export function _dataToHTML(input) {
  const inputLen = input.length;
  const result = ['<table>'];

  for (let row = 0; row < inputLen; row += 1) {
    const rowData = input[row];
    const columnsLen = rowData.length;
    const columnsResult = [];

    if (row === 0) {
      result.push('<tbody>');
    }

    for (let column = 0; column < columnsLen; column += 1) {
      const cellData = rowData[column];
      const parsedCellData = isEmpty(cellData) ?
        '' :
        cellData.toString().replace(/(<br(\s*|\/)>(\r\n|\n)?|\r\n|\n)/g, '<br>\r\n').replace(/\x20/gi, '&nbsp;').replace(/\t/gi, '&#9;');

      columnsResult.push(`<td>${parsedCellData}</td>`);
    }

    result.push('<tr>', ...columnsResult, '</tr>');

    if (row + 1 === inputLen) {
      result.push('</tbody>');
    }
  }

  result.push('</table>');

  return result.join('');
}

/**
 * Helper to verify and get CSSRules for the element.
 *
 * @param {Element} element Element to verify with selector text.
 * @param {String} selector Selector text from CSSRule.
 */
function matchCSSRules(element, selector) {
  let result;

  if (element.msMatchesSelector) {
    result = element.msMatchesSelector(selector);

  } else if (element.matches) {
    result = element.matches(selector);
  }

  return result;
}

/**
 * Converts HTMLTable or string into Handsontable configuration object.
 *
 * @param {Element|String} element Node element which should contain `<table>...</table>`.
 * @param {Document} [rootDocument]
 * @returns {Object} Return configuration object. Contains keys as DefaultSettings.
 */
// eslint-disable-next-line no-restricted-globals
export function htmlToGridSettings(element, rootDocument = document) {
  const settingsObj = {};
  const fragment = rootDocument.createDocumentFragment();
  const tempElem = rootDocument.createElement('div');
  fragment.appendChild(tempElem);

  let checkElement = element;

  if (typeof checkElement === 'string') {
    tempElem.insertAdjacentHTML('afterbegin', `${checkElement}`);
    checkElement = tempElem.querySelector('table');
  }

  if (!checkElement || !isHTMLTable(checkElement)) {
    return;
  }

  const styleElem = tempElem.querySelector('style');
  let styleSheet = null;
  let styleSheetArr = [];

  if (styleElem) {
    rootDocument.body.appendChild(styleElem);
    styleElem.disabled = true;
    styleSheet = styleElem.sheet;
    styleSheetArr = styleSheet ? Array.from(styleSheet.cssRules) : [];
    rootDocument.body.removeChild(styleElem);
  }

  const generator = tempElem.querySelector('meta[name$="enerator"]');
  const hasRowHeaders = checkElement.querySelector('tbody th') !== null;
  const countCols = Array.from(checkElement.querySelector('tr').cells).reduce((cols, cell) => cols + cell.colSpan, 0) - (hasRowHeaders ? 1 : 0);
  const fixedRowsBottom = checkElement.tFoot && Array.from(checkElement.tFoot.rows) || [];
  const fixedRowsTop = [];
  let hasColHeaders = false;
  let thRowsLen = 0;
  let countRows = 0;

  if (checkElement.tHead) {
    const thRows = Array.from(checkElement.tHead.rows).filter((tr) => {
      const isDataRow = tr.querySelector('td') !== null;

      if (isDataRow) {
        fixedRowsTop.push(tr);
      }

      return !isDataRow;
    });

    thRowsLen = thRows.length;
    hasColHeaders = thRowsLen > 0;

    if (thRowsLen > 1) {
      settingsObj.nestedHeaders = Array.from(thRows).reduce((rows, row) => {
        const headersRow = Array.from(row.cells).reduce((headers, header, currentIndex) => {
          if (hasRowHeaders && currentIndex === 0) {
            return headers;
          }

          const {
            colSpan: colspan,
            innerHTML,
          } = header;
          const nextHeader = colspan > 1 ? { label: innerHTML, colspan } : innerHTML;

          headers.push(nextHeader);

          return headers;
        }, []);

        rows.push(headersRow);

        return rows;
      }, []);

    } else if (hasColHeaders) {
      settingsObj.colHeaders = Array.from(thRows[0].children).reduce((headers, header, index) => {
        if (hasRowHeaders && index === 0) {
          return headers;
        }

        headers.push(header.innerHTML);

        return headers;
      }, []);
    }
  }

  if (fixedRowsTop.length) {
    settingsObj.fixedRowsTop = fixedRowsTop.length;
  }
  if (fixedRowsBottom.length) {
    settingsObj.fixedRowsBottom = fixedRowsBottom.length;
  }

  const dataRows = [
    ...fixedRowsTop,
    ...Array.from(checkElement.tBodies).reduce((sections, section) => {
      sections.push(...section.rows); return sections;
    }, []),
    ...fixedRowsBottom];

  countRows = dataRows.length;

  const dataArr = new Array(countRows);

  for (let r = 0; r < countRows; r++) {
    dataArr[r] = new Array(countCols);
  }

  const mergeCells = [];
  const rowHeaders = [];

  for (let row = 0; row < countRows; row++) {
    const tr = dataRows[row];
    const cells = Array.from(tr.cells);
    const cellsLen = cells.length;

    for (let cellId = 0; cellId < cellsLen; cellId++) {
      const cell = cells[cellId];
      const {
        nodeName,
        innerHTML,
        rowSpan: rowspan,
        colSpan: colspan,
      } = cell;
      const col = dataArr[row].findIndex(value => value === void 0);

      if (nodeName === 'TD') {
        if (rowspan > 1 || colspan > 1) {
          for (let rstart = row; rstart < row + rowspan; rstart++) {
            if (rstart < countRows) {
              for (let cstart = col; cstart < col + colspan; cstart++) {
                dataArr[rstart][cstart] = null;
              }
            }
          }

          const styleAttr = cell.getAttribute('style');
          const ignoreMerge = styleAttr && styleAttr.includes('mso-ignore:colspan');

          if (!ignoreMerge) {
            mergeCells.push({ col, row, rowspan, colspan });
          }
        }

        const cellStyle = styleSheetArr.reduce((settings, cssRule) => {
          if (cssRule.selectorText && matchCSSRules(cell, cssRule.selectorText)) {
            const { whiteSpace } = cssRule.style;

            if (whiteSpace) {
              settings.whiteSpace = whiteSpace;
            }
          }

          return settings;
        }, {});

        if (cellStyle.whiteSpace === 'nowrap') {
          dataArr[row][col] = innerHTML.replace(/[\r\n][\x20]{0,2}/gim, '\x20')
            .replace(/<br(\s*|\/)>/gim, '\r\n')
            .replace(/(<([^>]+)>)/gi, '')
            .replace(/&nbsp;/gi, '\x20');

        } else if (generator && /excel/gi.test(generator.content)) {
          dataArr[row][col] = innerHTML.replace(/<br(\s*|\/)>[\r\n]?[\x20]{0,2}/gim, '\r\n').replace(/(<([^>]+)>)/gi, '').replace(/&nbsp;/gi, '\x20');
        } else {
          dataArr[row][col] = innerHTML.replace(/<br(\s*|\/)>[\r\n]?/gim, '\r\n').replace(/(<([^>]+)>)/gi, '').replace(/&nbsp;/gi, '\x20');
        }

      } else {
        rowHeaders.push(innerHTML);
      }
    }
  }

  if (mergeCells.length) {
    settingsObj.mergeCells = mergeCells;
  }
  if (rowHeaders.length) {
    settingsObj.rowHeaders = rowHeaders;
  }

  if (dataArr.length) {
    settingsObj.data = dataArr;
  }

  return settingsObj;
}
