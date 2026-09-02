(function(Clazz
,Clazz_getClassName
,Clazz_newLongArray
,Clazz_doubleToByte
,Clazz_doubleToInt
,Clazz_doubleToLong
,Clazz_declarePackage
,Clazz_instanceOf
,Clazz_load
,Clazz_instantialize
,Clazz_decorateAsClass
,Clazz_floatToInt
,Clazz_floatToLong
,Clazz_makeConstructor
,Clazz_defineEnumConstant
,Clazz_exceptionOf
,Clazz_newIntArray
,Clazz_newFloatArray
,Clazz_declareType
,Clazz_prepareFields
,Clazz_superConstructor
,Clazz_newByteArray
,Clazz_declareInterface
,Clazz_newShortArray
,Clazz_innerTypeInstance
,Clazz_isClassDefined
,Clazz_prepareCallback
,Clazz_newArray
,Clazz_castNullAs
,Clazz_floatToShort
,Clazz_superCall
,Clazz_decorateAsType
,Clazz_newBooleanArray
,Clazz_newCharArray
,Clazz_implementOf
,Clazz_newDoubleArray
,Clazz_overrideConstructor
,Clazz_clone
,Clazz_doubleToShort
,Clazz_getInheritedLevel
,Clazz_getParamsType
,Clazz_isAF
,Clazz_isAB
,Clazz_isAI
,Clazz_isAS
,Clazz_isASS
,Clazz_isAP
,Clazz_isAFloat
,Clazz_isAII
,Clazz_isAFF
,Clazz_isAFFF
,Clazz_tryToSearchAndExecute
,Clazz_getStackTrace
,Clazz_inheritArgs
,Clazz_alert
,Clazz_defineMethod
,Clazz_overrideMethod
,Clazz_declareAnonymous
//,Clazz_checkPrivateMethod
,Clazz_cloneFinals
){
var $t$;
//var c$;
Clazz_declarePackage("JSV.export");
Clazz_load(["JSV.api.ExportInterface"], "JSV.export.Exporter", ["java.io.File", "JU.OC", "$.PT", "JSV.common.ExportType", "$.JSVFileManager", "$.JSViewer"], function(){
var c$ = Clazz_declareType(JSV["export"], "Exporter", null, JSV.api.ExportInterface);
/*LV!1824 unnec constructor*/Clazz_overrideMethod(c$, "write", 
function(viewer, tokens, forInkscape){
if (tokens == null) return this.printPDF(viewer, null, null, false);
var type = null;
var fileName = null;
var width = 0;
var height = 0;
var ptFileName = 1;
var eType;
var out;
var jsvp = viewer.selectedPanel;
try {
switch (tokens.size()) {
default:
return "WRITE what?";
case 1:
fileName = JU.PT.trimQuotes(tokens.get(0));
var ext = fileName;
var pt = fileName.lastIndexOf(".");
if (pt >= 0) {
ext = fileName.substring(pt + 1);
type = "XY";
}if (jsvp == null) return null;
eType = JSV.common.ExportType.getType(ext);
switch (eType) {
case JSV.common.ExportType.PDF:
case JSV.common.ExportType.PNG:
case JSV.common.ExportType.JPG:
out = (pt >= 0 ? viewer.getOutputChannel( new java.io.File(fileName).getAbsolutePath(), false) : null);
return this.exportTheSpectrum(viewer, eType, out, null, -1, -1, null, false);
default:
viewer.fileHelper.setFileChooser(eType);
var items = this.getExportableItems(viewer, eType.equals(JSV.common.ExportType.SOURCE));
var index = (items == null ? -1 : viewer.getOptionFromDialog(items, "Export", "Choose a spectrum to export"));
if (index == -2147483648) return null;
var file = viewer.fileHelper.getFile(this.getSuggestedFileName(viewer, eType), jsvp, true);
if (file == null) return null;
out = viewer.getOutputChannel(file.getFullPath(), false);
var msg = this.exportSpectrumOrImage(viewer, eType, index, out, 0, 0);
var isOK = msg.startsWith("OK");
if (isOK) viewer.si.siUpdateRecentMenus(file.getFullPath());
out.closeChannel();
return msg;
}
case 4:
width = JU.PT.parseInt(tokens.get(1));
height = JU.PT.parseInt(tokens.get(2));
if (width < 0 || height < 0) return "width and height must be positive: " + tokens.get(1) + " " + tokens.get(2);
ptFileName = 3;
case 2:
type = tokens.get(0).toUpperCase();
fileName = JU.PT.trimQuotes(tokens.get(ptFileName));
break;
}
var ext = fileName.substring(fileName.lastIndexOf(".") + 1).toUpperCase();
if (ext.equals("BASE64")) {
fileName = ";base64,";
} else if (ext.equals("JDX")) {
if (type == null) type = "XY";
} else if (JSV.common.ExportType.isExportMode(ext)) {
type = ext;
} else if (JSV.common.ExportType.isExportMode(type)) {
fileName += "." + type;
}eType = JSV.common.ExportType.getType(type);
if (forInkscape && eType === JSV.common.ExportType.SVG) eType = JSV.common.ExportType.SVGI;
out = viewer.getOutputChannel(fileName, false);
return this.exportSpectrumOrImage(viewer, eType, -1, out, width, height);
} catch (e) {
if (Clazz_exceptionOf(e, Exception)){
System.out.println(e);
return null;
} else {
throw e;
}
}
}, "JSV.common.JSViewer,JU.Lst,~B");
Clazz_defineMethod(c$, "exportSpectrumOrImage", 
function(viewer, eType, index, out, width, height){
var spec;
var pd = viewer.pd();
if (index < 0 && (index = pd.getCurrentSpectrumIndex()) < 0) return "Error exporting spectrum: No spectrum selected";
spec = pd.getSpectrumAt(index);
var startIndex = pd.getStartingPointIndex(index);
var endIndex = pd.getEndingPointIndex(index);
var msg = null;
try {
var asBase64 = out.isBase64();
msg = this.exportTheSpectrumWH(viewer, eType, out, spec, startIndex, endIndex, width, height, asBase64);
if (asBase64) return msg;
if (msg.startsWith("OK")) return "OK - Exported " + eType.name() + ": " + out.getFileName() + msg.substring(2);
} catch (ioe) {
if (Clazz_exceptionOf(ioe, Exception)){
msg = ioe.toString();
} else {
throw ioe;
}
}
return "Error exporting " + out.getFileName() + ": " + msg;
}, "JSV.common.JSViewer,JSV.common.ExportType,~N,JU.OC,~N,~N");
Clazz_defineMethod(c$, "exportTheSpectrum", 
function(viewer, mode, out, spec, startIndex, endIndex, pd, asBase64){
return this.exportTheSpectrumWH(viewer, mode, out, spec, startIndex, endIndex, 0, 0, asBase64);
}, "JSV.common.JSViewer,JSV.common.ExportType,JU.OC,JSV.common.Spectrum,~N,~N,JSV.common.PanelData,~B");
Clazz_defineMethod(c$, "exportTheSpectrumWH", 
function(viewer, mode, out, spec, startIndex, endIndex, width, height, asBase64){
var file = null;
var jsvp = viewer.selectedPanel;
var type = mode.name();
switch (mode) {
case JSV.common.ExportType.AML:
case JSV.common.ExportType.CML:
case JSV.common.ExportType.SVG:
case JSV.common.ExportType.SVGI:
break;
case JSV.common.ExportType.DIF:
case JSV.common.ExportType.DIFDUP:
case JSV.common.ExportType.FIX:
case JSV.common.ExportType.PAC:
case JSV.common.ExportType.SQZ:
case JSV.common.ExportType.XY:
type = "JDX";
break;
case JSV.common.ExportType.JPG:
case JSV.common.ExportType.PNG:
if (jsvp == null) return null;
if (out == null) {
viewer.fileHelper.setFileChooser(mode);
var name = this.getSuggestedFileName(viewer, mode);
file = viewer.fileHelper.getFile(name, jsvp, true);
if (file == null) return null;
}viewer.setCreatingImage(true);
var ret = jsvp.saveImage(type.toLowerCase(), file, out, width, height);
viewer.setCreatingImage(false);
return ret;
case JSV.common.ExportType.PDF:
return this.printPDF(viewer, (out == null ? "PDF" : null), out, asBase64);
case JSV.common.ExportType.SOURCE:
if (jsvp == null) return null;
var data = jsvp.getPanelData().getSpectrum().getInlineData();
if (data != null) {
out.append(data);
out.closeChannel();
return "OK " + out.getByteCount() + " bytes";
}var path = jsvp.getPanelData().getSpectrum().getFilePath();
return JSV["export"].Exporter.fileCopy(path, out);
case JSV.common.ExportType.UNK:
return null;
}
return (JSV.common.JSViewer.getInterface("JSV.export." + type.toUpperCase() + "Exporter")).exportTheSpectrum(viewer, mode, out, spec, startIndex, endIndex, null, false);
}, "JSV.common.JSViewer,JSV.common.ExportType,JU.OC,JSV.common.Spectrum,~N,~N,~N,~N,~B");
Clazz_defineMethod(c$, "printPDF", 
function(viewer, pdfFileName, out, isBase64){
var isJob = (out != null);
if (!isBase64 && !viewer.si.isSigned()) return "Error: Applet must be signed for the PRINT command.";
var pd = viewer.pd();
if (pd == null) return null;
var useDialog = false;
var pl;
{
}pl = viewer.getPrintLayout(isJob);
if (pl == null) return null;
if (!useDialog) pl.asPDF = true;
if (isJob && pl.asPDF && out == null) {
isJob = false;
pdfFileName = "PDF";
}var jsvp = viewer.selectedPanel;
if (!isBase64 && !isJob && viewer.hasDisplay) {
var helper = viewer.fileHelper;
helper.setFileChooser(JSV.common.ExportType.PDF);
if (pdfFileName == null || pdfFileName.equals("?") || pdfFileName.equalsIgnoreCase("PDF")) pdfFileName = this.getSuggestedFileName(viewer, JSV.common.ExportType.PDF);
var file = helper.getFile(pdfFileName, jsvp, true);
if (file == null) return null;
if (!JSV.common.JSViewer.isJS) viewer.setProperty("directoryLastExportedFile", helper.setDirLastExported(file.getParentAsFile().getFullPath()));
pdfFileName = file.getFullPath();
}var s = null;
try {
if (out == null) {
out = (isJob ? null : isBase64 ?  new JU.OC().setParams(null, ";base64,", false, null) : viewer.getOutputChannel(pdfFileName, true));
}var printJobTitle = "";
jsvp.printPanel(pl, out, printJobTitle);
s = "OK " + out.toString();
} catch (e) {
if (Clazz_exceptionOf(e, Exception)){
jsvp.showMessage(e.toString(), "File Error");
} else {
throw e;
}
}
return s;
}, "JSV.common.JSViewer,~S,JU.OC,~B");
Clazz_defineMethod(c$, "getExportableItems", 
function(viewer, isSameType){
var pd = viewer.pd();
var isView = viewer.currentSource.isView;
var nSpectra = pd.getNumberOfSpectraInCurrentSet();
if (nSpectra == 1 || !isView && isSameType || pd.getCurrentSpectrumIndex() >= 0) return null;
var items =  new Array(nSpectra);
for (var i = 0; i < nSpectra; i++) items[i] = pd.getSpectrumAt(i).getTitle();

return items;
}, "JSV.common.JSViewer,~B");
Clazz_defineMethod(c$, "getSuggestedFileName", 
function(viewer, imode){
var pd = viewer.pd();
var sourcePath = pd.getSpectrum().getFilePath();
var newName = JSV.common.JSVFileManager.getTagName(sourcePath);
if (newName.startsWith("$")) newName = newName.substring(1);
var pt = newName.lastIndexOf(".");
var name = (pt < 0 ? newName : newName.substring(0, pt));
if (name.startsWith("http:") || name.startsWith("https:")) {
name = name.substring(name.lastIndexOf('/') + 1);
}var ext = ".jdx";
switch (imode) {
case JSV.common.ExportType.XY:
case JSV.common.ExportType.FIX:
case JSV.common.ExportType.PAC:
case JSV.common.ExportType.SQZ:
case JSV.common.ExportType.DIF:
case JSV.common.ExportType.DIFDUP:
if (!(name.endsWith("_" + imode))) name += "_" + imode;
ext = ".jdx";
break;
case JSV.common.ExportType.AML:
ext = ".xml";
break;
case JSV.common.ExportType.SOURCE:
if (!(name.endsWith("_" + imode))) name += "_" + imode;
var lc = (sourcePath == null ? "JSV" : sourcePath.toLowerCase());
ext = (lc.endsWith(".zip") ? ".zip" : lc.endsWith(".jdx") ? ".jdx" : "");
break;
case JSV.common.ExportType.JPG:
case JSV.common.ExportType.PNG:
case JSV.common.ExportType.PDF:
default:
ext = "." + imode.toString().toLowerCase();
}
if (viewer.currentSource.isView) name = "view";
name += ext;
return name;
}, "JSV.common.JSViewer,JSV.common.ExportType");
c$.fileCopy = Clazz_defineMethod(c$, "fileCopy", 
function(name, out){
try {
var br = JSV.common.JSVFileManager.getBufferedReaderFromName(name);
var line = null;
while ((line = br.readLine()) != null) {
out.append(line);
out.append(JSV["export"].Exporter.newLine);
}
out.closeChannel();
return "OK " + out.getByteCount() + " bytes";
} catch (e) {
if (Clazz_exceptionOf(e, Exception)){
return e.toString();
} else {
throw e;
}
}
}, "~S,JU.OC");
c$.newLine = System.getProperty("line.separator");
});
;//5.0.1-v7 Mon Aug 17 10:07:10 MDT 2026
Clazz_declarePackage("JSV.api");
Clazz_declareInterface(JSV.api, "ExportInterface", JSV.api.JSVExporter);
;//5.0.1-v7 Mon Aug 17 10:07:10 MDT 2026
Clazz_declarePackage("JSV.api");
Clazz_declareInterface(JSV.api, "JSVExporter");
;//5.0.1-v7 Mon Aug 17 10:07:10 MDT 2026
Clazz_declarePackage("JSV.api");
Clazz_declareInterface(JSV.api, "JSVPdfWriter");
;//5.0.1-v7 Mon Aug 17 10:07:10 MDT 2026
Clazz_declarePackage("JSV.common");
Clazz_load(["JSV.api.JSVPdfWriter", "J.api.GenericGraphics"], "JSV.common.PDFWriter", ["java.util.Hashtable", "javajs.export.PDFCreator", "JSV.common.JSVersion"], function(){
var c$ = Clazz_decorateAsClass(function(){
this.g2d0 = null;
this.date = null;
this.pdf = null;
this.inPath = false;
this.rgb = null;
Clazz_instantialize(this, arguments);}, JSV.common, "PDFWriter", null, [J.api.GenericGraphics, JSV.api.JSVPdfWriter]);
Clazz_prepareFields (c$, function(){
this.rgb =  Clazz_newFloatArray (3, 0);
});
Clazz_makeConstructor(c$, 
function(){
this.pdf =  new javajs["export"].PDFCreator();
});
Clazz_overrideMethod(c$, "createPdfDocument", 
function(panel, pl, os){
var isLandscape = pl.layout.equals("landscape");
this.date = pl.date;
this.pdf.setOutputStream(os);
this.g2d0 = panel.getPanelData().g2d0;
try {
this.pdf.newDocument(pl.paperWidth, pl.paperHeight, isLandscape);
var ht =  new java.util.Hashtable();
ht.put("Producer", JSV.common.JSVersion.VERSION);
ht.put("Creator", "JSpecView " + JSV.common.JSVersion.VERSION);
ht.put("Author", "JSpecView");
if (this.date != null) ht.put("CreationDate", this.date);
this.pdf.addInfo(ht);
panel.getPanelData().printPdf(this, pl);
this.pdf.closeDocument();
} catch (e) {
if (Clazz_exceptionOf(e, Exception)){
panel.showMessage(e.toString(), "PDF Creation Error");
} else {
throw e;
}
}
}, "JSV.api.JSVPanel,JSV.common.PrintLayout,java.io.OutputStream");
Clazz_overrideMethod(c$, "canDoLineTo", 
function(){
return true;
});
Clazz_overrideMethod(c$, "doStroke", 
function(g, isBegin){
this.inPath = isBegin;
if (!this.inPath) this.pdf.stroke();
}, "~O,~B");
Clazz_overrideMethod(c$, "drawCircle", 
function(g, x, y, diameter){
this.pdf.doCircle(x, y, Clazz_doubleToInt(diameter / 2.0), false);
}, "~O,~N,~N,~N");
Clazz_overrideMethod(c$, "drawLine", 
function(g, x0, y0, x1, y1){
this.pdf.moveto(x0, y0);
this.pdf.lineto(x1, y1);
if (!this.inPath) this.pdf.stroke();
}, "~O,~N,~N,~N,~N");
Clazz_overrideMethod(c$, "drawPolygon", 
function(g, axPoints, ayPoints, nPoints){
this.pdf.doPolygon(axPoints, ayPoints, nPoints, false);
}, "~O,~A,~A,~N");
Clazz_overrideMethod(c$, "drawRect", 
function(g, x, y, width, height){
this.pdf.doRect(x, y, width, height, false);
}, "~O,~N,~N,~N,~N");
Clazz_overrideMethod(c$, "drawString", 
function(g, s, x, y){
this.pdf.drawStringRotated(s, x, y, 0);
}, "~O,~S,~N,~N");
Clazz_overrideMethod(c$, "drawStringRotated", 
function(g, s, x, y, angle){
this.pdf.drawStringRotated(s, x, y, Clazz_doubleToInt(angle));
}, "~O,~S,~N,~N,~N");
Clazz_overrideMethod(c$, "fillBackground", 
function(g, bgcolor){
}, "~O,javajs.api.GenericColor");
Clazz_overrideMethod(c$, "fillCircle", 
function(g, x, y, diameter){
this.pdf.doCircle(x, y, Clazz_doubleToInt(diameter / 2.0), true);
}, "~O,~N,~N,~N");
Clazz_overrideMethod(c$, "fillPolygon", 
function(g, ayPoints, axPoints, nPoints){
this.pdf.doPolygon(axPoints, ayPoints, nPoints, true);
}, "~O,~A,~A,~N");
Clazz_overrideMethod(c$, "fillRect", 
function(g, x, y, width, height){
this.pdf.doRect(x, y, width, height, true);
}, "~O,~N,~N,~N,~N");
Clazz_overrideMethod(c$, "lineTo", 
function(g, x, y){
this.pdf.lineto(x, y);
}, "~O,~N,~N");
Clazz_overrideMethod(c$, "setGraphicsColor", 
function(g, c){
var p = c.getRGB();
this.rgb[0] = ((p >> 16) & 0xFF) / 255;
this.rgb[1] = ((p >> 8) & 0xFF) / 255;
this.rgb[2] = ((p & 0xFF)) / 255;
this.pdf.setColor(this.rgb, true);
this.pdf.setColor(this.rgb, false);
}, "~O,javajs.api.GenericColor");
Clazz_overrideMethod(c$, "setFont", 
function(g, font){
var fname = "/Helvetica";
switch (font.idFontStyle) {
case 1:
fname += "-Bold";
break;
case 3:
fname += "-BoldOblique";
break;
case 2:
fname += "-Oblique";
break;
}
this.pdf.setFont(fname, font.fontSizeNominal);
return font;
}, "~O,JU.Font");
Clazz_overrideMethod(c$, "setStrokeBold", 
function(g, tf){
this.pdf.setLineWidth(tf ? 2 : 1);
}, "~O,~B");
Clazz_overrideMethod(c$, "translateScale", 
function(g, x, y, scale){
this.pdf.translateScale(x, y, scale);
}, "~O,~N,~N,~N");
Clazz_overrideMethod(c$, "newGrayScaleImage", 
function(g, image, width, height, buffer){
this.pdf.addImageResource(image, width, height, buffer, false);
return image;
}, "~O,~O,~N,~N,~A");
Clazz_overrideMethod(c$, "drawGrayScaleImage", 
function(g, image, destX0, destY0, destX1, destY1, srcX0, srcY0, srcX1, srcY1){
this.pdf.drawImage(image, destX0, destY0, destX1, destY1, srcX0, srcY0, srcX1, srcY1);
}, "~O,~O,~N,~N,~N,~N,~N,~N,~N,~N");
Clazz_overrideMethod(c$, "setWindowParameters", 
function(width, height){
}, "~N,~N");
Clazz_defineMethod(c$, "getColor1", 
function(argb){
return this.g2d0.getColor1(argb);
}, "~N");
Clazz_defineMethod(c$, "getColor3", 
function(red, green, blue){
return this.g2d0.getColor3(red, green, blue);
}, "~N,~N,~N");
Clazz_defineMethod(c$, "getColor4", 
function(r, g, b, a){
return this.g2d0.getColor4(r, g, b, a);
}, "~N,~N,~N,~N");
});
;//5.0.1-v7 Mon Aug 17 10:07:10 MDT 2026
Clazz_declarePackage("javajs.export");
Clazz_load(null, "javajs.export.PDFCreator", ["java.util.Hashtable", "javajs.export.PDFObject", "JU.Lst", "$.SB"], function(){
var c$ = Clazz_decorateAsClass(function(){
this.os = null;
this.indirectObjects = null;
this.root = null;
this.graphics = null;
this.pt = 0;
this.xrefPt = 0;
this.count = 0;
this.height = 0;
this.width = 0;
this.fonts = null;
this.images = null;
Clazz_instantialize(this, arguments);}, javajs["export"], "PDFCreator", null);
/*LV!1824 unnec constructor*/Clazz_defineMethod(c$, "setOutputStream", 
function(os){
this.os = os;
}, "java.io.OutputStream");
Clazz_defineMethod(c$, "newDocument", 
function(paperWidth, paperHeight, isLandscape){
this.width = (isLandscape ? paperHeight : paperWidth);
this.height = (isLandscape ? paperWidth : paperHeight);
System.out.println("Creating PDF with width=" + this.width + " and height=" + this.height);
this.fonts =  new java.util.Hashtable();
this.indirectObjects =  new JU.Lst();
this.root = this.newObject("Catalog");
var pages = this.newObject("Pages");
var page = this.newObject("Page");
var pageContents = this.newObject(null);
this.graphics = this.newObject("XObject");
this.root.addDef("Pages", pages.getRef());
pages.addDef("Count", "1");
pages.addDef("Kids", "[ " + page.getRef() + " ]");
page.addDef("Parent", pages.getRef());
page.addDef("MediaBox", "[ 0 0 " + paperWidth + " " + paperHeight + " ]");
if (isLandscape) page.addDef("Rotate", "90");
pageContents.addDef("Length", "?");
pageContents.append((isLandscape ? "q 0 1 1 0 0 0 " : "q 1 0 0 -1 0 " + (paperHeight)) + " cm /" + this.graphics.getID() + " Do Q");
page.addDef("Contents", pageContents.getRef());
this.addProcSet(page);
this.addProcSet(this.graphics);
this.graphics.addDef("Subtype", "/Form");
this.graphics.addDef("FormType", "1");
this.graphics.addDef("BBox", "[0 0 " + this.width + " " + this.height + "]");
this.graphics.addDef("Matrix", "[1 0 0 1 0 0]");
this.graphics.addDef("Length", "?");
page.addResource("XObject", this.graphics.getID(), this.graphics.getRef());
this.g("q 1 w 1 J 1 j 10 M []0 d q ");
this.clip(0, 0, this.width, this.height);
}, "~N,~N,~B");
Clazz_defineMethod(c$, "addProcSet", 
function(o){
o.addResource(null, "ProcSet", "[/PDF /Text /ImageB /ImageC /ImageI]");
}, "javajs.export.PDFObject");
Clazz_defineMethod(c$, "clip", 
function(x1, y1, x2, y2){
this.moveto(x1, y1);
this.lineto(x2, y1);
this.lineto(x2, y2);
this.lineto(x1, y2);
this.g("h W n");
}, "~N,~N,~N,~N");
Clazz_defineMethod(c$, "moveto", 
function(x, y){
this.g(x + " " + y + " m");
}, "~N,~N");
Clazz_defineMethod(c$, "lineto", 
function(x, y){
this.g(x + " " + y + " l");
}, "~N,~N");
Clazz_defineMethod(c$, "newObject", 
function(type){
var o =  new javajs["export"].PDFObject(++this.count);
if (type != null) o.addDef("Type", "/" + type);
this.indirectObjects.addLast(o);
return o;
}, "~S");
Clazz_defineMethod(c$, "addInfo", 
function(data){
var info =  new java.util.Hashtable();
for (var e, $e = data.entrySet().iterator (); $e.hasNext()&& ((e = $e.next ()) || true);) {
var value = "(" + e.getValue().$replace(')', '_').$replace('(', '_') + ")";
info.put(e.getKey(), value);
}
this.root.addDef("Info", info);
}, "java.util.Map");
Clazz_defineMethod(c$, "addFontResource", 
function(fname){
var f = this.newObject("Font");
this.fonts.put(fname, f);
f.addDef("BaseFont", fname);
f.addDef("Encoding", "/WinAnsiEncoding");
f.addDef("Subtype", "/Type1");
this.graphics.addResource("Font", f.getID(), f.getRef());
return f;
}, "~S");
Clazz_defineMethod(c$, "addImageResource", 
function(newImage, width, height, buffer, isRGB){
var imageObj = this.newObject("XObject");
if (this.images == null) this.images =  new java.util.Hashtable();
this.images.put(newImage, imageObj);
imageObj.addDef("Subtype", "/Image");
imageObj.addDef("Length", "?");
imageObj.addDef("ColorSpace", isRGB ? "/DeviceRGB" : "/DeviceGray");
imageObj.addDef("BitsPerComponent", "8");
imageObj.addDef("Width", "" + width);
imageObj.addDef("Height", "" + height);
this.graphics.addResource("XObject", imageObj.getID(), imageObj.getRef());
var n = buffer.length;
var stream =  Clazz_newByteArray (n * (isRGB ? 3 : 1), 0);
if (isRGB) {
for (var i = 0, pt = 0; i < n; i++) {
stream[pt++] = ((buffer[i] >> 16) & 0xFF);
stream[pt++] = ((buffer[i] >> 8) & 0xFF);
stream[pt++] = (buffer[i] & 0xFF);
}
} else {
for (var i = 0; i < n; i++) stream[i] = buffer[i];

}imageObj.setStream(stream);
this.graphics.addResource("XObject", imageObj.getID(), imageObj.getRef());
}, "~O,~N,~N,~A,~B");
Clazz_defineMethod(c$, "g", 
function(cmd){
this.graphics.append(cmd).appendC('\n');
}, "~S");
Clazz_defineMethod(c$, "output", 
function(s){
var b = s.getBytes();
this.os.write(b, 0, b.length);
this.pt += b.length;
}, "~S");
Clazz_defineMethod(c$, "closeDocument", 
function(){
this.g("Q Q");
this.outputHeader();
this.writeObjects();
this.writeXRefTable();
this.writeTrailer();
this.os.flush();
this.os.close();
});
Clazz_defineMethod(c$, "outputHeader", 
function(){
this.output("%PDF-1.3\n%");
var b =  Clazz_newByteArray(-1, [-1, -1, -1, -1]);
this.os.write(b, 0, b.length);
this.pt += 4;
this.output("\n");
});
Clazz_defineMethod(c$, "writeTrailer", 
function(){
var trailer =  new javajs["export"].PDFObject(-2);
this.output("trailer");
trailer.addDef("Size", "" + this.indirectObjects.size());
trailer.addDef("Root", this.root.getRef());
trailer.output(this.os);
this.output("startxref\n");
this.output("" + this.xrefPt + "\n");
this.output("%%EOF\n");
});
Clazz_defineMethod(c$, "writeObjects", 
function(){
var nObj = this.indirectObjects.size();
for (var i = 0; i < nObj; i++) {
var o = this.indirectObjects.get(i);
if (!o.isFont()) continue;
o.pt = this.pt;
this.pt += o.output(this.os);
}
for (var i = 0; i < nObj; i++) {
var o = this.indirectObjects.get(i);
if (o.isFont()) continue;
o.pt = this.pt;
this.pt += o.output(this.os);
}
});
Clazz_defineMethod(c$, "writeXRefTable", 
function(){
this.xrefPt = this.pt;
var nObj = this.indirectObjects.size();
var sb =  new JU.SB();
sb.append("xref\n0 " + (nObj + 1) + "\n0000000000 65535 f\r\n");
for (var i = 0; i < nObj; i++) {
var o = this.indirectObjects.get(i);
var s = "0000000000" + o.pt;
sb.append(s.substring(s.length - 10));
sb.append(" 00000 n\r\n");
}
this.output(sb.toString());
});
Clazz_defineMethod(c$, "canDoLineTo", 
function(){
return true;
});
Clazz_defineMethod(c$, "fill", 
function(){
this.g("f");
});
Clazz_defineMethod(c$, "stroke", 
function(){
this.g("S");
});
Clazz_defineMethod(c$, "doCircle", 
function(x, y, r, doFill){
var d = r * 4 * (Math.sqrt(2) - 1) / 3;
var dx = x;
var dy = y;
this.g((dx + r) + " " + dy + " m");
this.g((dx + r) + " " + (dy + d) + " " + (dx + d) + " " + (dy + r) + " " + (dx) + " " + (dy + r) + " " + " c");
this.g((dx - d) + " " + (dy + r) + " " + (dx - r) + " " + (dy + d) + " " + (dx - r) + " " + (dy) + " c");
this.g((dx - r) + " " + (dy - d) + " " + (dx - d) + " " + (dy - r) + " " + (dx) + " " + (dy - r) + " c");
this.g((dx + d) + " " + (dy - r) + " " + (dx + r) + " " + (dy - d) + " " + (dx + r) + " " + (dy) + " c");
this.g(doFill ? "f" : "s");
}, "~N,~N,~N,~B");
Clazz_defineMethod(c$, "doPolygon", 
function(axPoints, ayPoints, nPoints, doFill){
this.moveto(axPoints[0], ayPoints[0]);
for (var i = 1; i < nPoints; i++) this.lineto(axPoints[i], ayPoints[i]);

this.g(doFill ? "f" : "s");
}, "~A,~A,~N,~B");
Clazz_defineMethod(c$, "doRect", 
function(x, y, width, height, doFill){
this.g(x + " " + y + " " + width + " " + height + " re " + (doFill ? "f" : "s"));
}, "~N,~N,~N,~N,~B");
Clazz_defineMethod(c$, "drawImage", 
function(image, destX0, destY0, destX1, destY1, srcX0, srcY0, srcX1, srcY1){
var imageObj = this.images.get(image);
if (imageObj == null) return;
this.g("q");
this.clip(destX0, destY0, destX1, destY1);
var iw = Double.parseDouble(imageObj.getDef("Width"));
var ih = Double.parseDouble(imageObj.getDef("Height"));
var dw = (destX1 - destX0 + 1);
var dh = (destY1 - destY0 + 1);
var sw = (srcX1 - srcX0 + 1);
var sh = (srcY1 - srcY0 + 1);
var scaleX = dw / sw;
var scaleY = dh / sh;
var transX = destX0 - srcX0 * scaleX;
var transY = destY0 + (ih - srcY0) * scaleY;
this.g(scaleX * iw + " 0 0 " + -scaleY * ih + " " + transX + " " + transY + " cm");
this.g("/" + imageObj.getID() + " Do");
this.g("Q");
}, "~O,~N,~N,~N,~N,~N,~N,~N,~N");
Clazz_defineMethod(c$, "drawStringRotated", 
function(s, x, y, angle){
this.g("q " + this.getRotation(angle) + " " + x + " " + y + " cm BT(" + s + ")Tj ET Q");
}, "~S,~N,~N,~N");
Clazz_defineMethod(c$, "getRotation", 
function(angle){
var cos = 0;
var sin = 0;
switch (angle) {
case 0:
cos = 1;
break;
case 90:
sin = 1;
break;
case -90:
sin = -1;
break;
case 180:
cos = -1;
break;
default:
var a = (angle / 180.0 * 3.141592653589793);
cos = Math.cos(a);
sin = Math.sin(a);
if (Math.abs(cos) < 0.0001) cos = 0;
if (Math.abs(sin) < 0.0001) sin = 0;
}
return cos + " " + sin + " " + sin + " " + -cos;
}, "~N");
Clazz_defineMethod(c$, "setColor", 
function(rgb, isFill){
this.g(rgb[0] + " " + rgb[1] + " " + rgb[2] + (isFill ? " rg" : " RG"));
}, "~A,~B");
Clazz_defineMethod(c$, "setFont", 
function(fname, size){
var f = this.fonts.get(fname);
if (f == null) f = this.addFontResource(fname);
this.g("/" + f.getID() + " " + size + " Tf");
}, "~S,~N");
Clazz_defineMethod(c$, "setLineWidth", 
function(width){
this.g(width + " w");
}, "~N");
Clazz_defineMethod(c$, "translateScale", 
function(x, y, scale){
this.g(scale + " 0 0 " + scale + " " + x + " " + y + " cm");
}, "~N,~N,~N");
});
;//5.0.1-v7 Mon Aug 17 10:07:10 MDT 2026
Clazz_declarePackage("javajs.export");
Clazz_load(["JU.SB"], "javajs.export.PDFObject", ["java.io.ByteArrayOutputStream", "java.util.Hashtable", "java.util.zip.Deflater", "$.DeflaterOutputStream"], function(){
var c$ = Clazz_decorateAsClass(function(){
this.dictionary = null;
this.stream = null;
this.index = 0;
this.type = null;
this.len = 0;
this.pt = 0;
Clazz_instantialize(this, arguments);}, javajs["export"], "PDFObject", JU.SB);
Clazz_makeConstructor(c$, 
function(index){
Clazz_superConstructor (this, javajs["export"].PDFObject, []);
this.index = index;
}, "~N");
Clazz_defineMethod(c$, "getRef", 
function(){
return this.index + " 0 R";
});
Clazz_defineMethod(c$, "getID", 
function(){
return this.type.substring(0, 1) + this.index;
});
Clazz_defineMethod(c$, "isFont", 
function(){
return "Font".equals(this.type);
});
Clazz_defineMethod(c$, "setStream", 
function(stream){
this.stream = stream;
}, "~A");
Clazz_defineMethod(c$, "getDef", 
function(key){
return this.dictionary.get(key);
}, "~S");
Clazz_defineMethod(c$, "addDef", 
function(key, value){
if (this.dictionary == null) this.dictionary =  new java.util.Hashtable();
this.dictionary.put(key, value);
if (key.equals("Type")) this.type = (value).substring(1);
}, "~S,~O");
Clazz_defineMethod(c$, "setAsStream", 
function(){
this.stream = this.toBytes(0, -1);
this.setLength(0);
});
Clazz_defineMethod(c$, "output", 
function(os){
if (this.index > 0) {
var s = this.index + " 0 obj\n";
this.write(os, s.getBytes(), 0);
}var streamLen = 0;
if (this.dictionary != null) {
if (this.dictionary.containsKey("Length")) {
if (this.stream == null) this.setAsStream();
streamLen = this.stream.length;
var doDeflate = (streamLen > 1000);
if (doDeflate) {
var deflater =  new java.util.zip.Deflater(9);
var outBytes =  new java.io.ByteArrayOutputStream(1024);
var compBytes =  new java.util.zip.DeflaterOutputStream(outBytes, deflater);
compBytes.write(this.stream, 0, streamLen);
compBytes.finish();
this.stream = outBytes.toByteArray();
this.dictionary.put("Filter", "/FlateDecode");
streamLen = this.stream.length;
}this.dictionary.put("Length", "" + streamLen);
}this.write(os, this.getDictionaryText(this.dictionary, "\n").getBytes(), 0);
}if (this.length() > 0) this.write(os, this.toString().getBytes(), 0);
if (this.stream != null) {
this.write(os, "stream\r\n".getBytes(), 0);
this.write(os, this.stream, streamLen);
this.write(os, "\r\nendstream\r\n".getBytes(), 0);
}if (this.index > 0) this.write(os, "endobj\n".getBytes(), 0);
return this.len;
}, "java.io.OutputStream");
Clazz_defineMethod(c$, "write", 
function(os, bytes, nBytes){
if (nBytes == 0) nBytes = bytes.length;
this.len += nBytes;
os.write(bytes, 0, nBytes);
}, "java.io.OutputStream,~A,~N");
Clazz_defineMethod(c$, "getDictionaryText", 
function(d, nl){
var sb =  new JU.SB();
sb.append("<<");
if (d.containsKey("Type")) sb.append("/Type").appendO(d.get("Type"));
for (var e, $e = d.entrySet().iterator (); $e.hasNext()&& ((e = $e.next ()) || true);) {
var s = e.getKey();
if (s.equals("Type") || s.startsWith("!")) continue;
sb.append("/" + s);
var o = e.getValue();
if (Clazz_instanceOf(o,"java.util.Map")) {
sb.append((this.getDictionaryText(o, "")));
continue;
}s = e.getValue();
if (!s.startsWith("/")) sb.append(" ");
sb.appendO(s);
}
return (sb.length() > 3 ? sb.append(">>").append(nl).toString() : "");
}, "java.util.Map,~S");
Clazz_defineMethod(c$, "createSubdict", 
function(d0, dict){
var d = d0.get(dict);
if (d == null) d0.put(dict, d =  new java.util.Hashtable());
return d;
}, "java.util.Map,~S");
Clazz_defineMethod(c$, "addResource", 
function(type, key, value){
var r = this.createSubdict(this.dictionary, "Resources");
if (type != null) r = this.createSubdict(r, type);
r.put(key, value);
}, "~S,~S,~S");
});
;//5.0.1-v7 Mon Aug 17 10:07:10 MDT 2026
Clazz_declarePackage("java.util.zip");
Clazz_load(["com.jcraft.jzlib.Deflater"], "java.util.zip.Deflater", null, function(){
var c$ = Clazz_declareType(java.util.zip, "Deflater", com.jcraft.jzlib.Deflater);
Clazz_makeConstructor(c$, 
function(compressionLevel){
if (compressionLevel != 2147483647) this.init(compressionLevel, 0, false);
}, "~N");
});
;//5.0.1-v7 Fri Nov 21 04:39:22 CST 2025
Clazz_declarePackage("java.util.zip");
Clazz_load(["com.jcraft.jzlib.DeflaterOutputStream"], "java.util.zip.DeflaterOutputStream", null, function(){
var c$ = Clazz_declareType(java.util.zip, "DeflaterOutputStream", com.jcraft.jzlib.DeflaterOutputStream);
Clazz_makeConstructor(c$, 
function(){
Clazz_superConstructor (this, java.util.zip.DeflaterOutputStream, []);
});
Clazz_makeConstructor(c$, 
function(bos, deflater){
Clazz_superConstructor (this, java.util.zip.DeflaterOutputStream, []);
this.setDOS(bos, deflater);
}, "java.io.ByteArrayOutputStream,java.util.zip.Deflater");
Clazz_defineMethod(c$, "setDOS", 
function(out, deflater){
this.jzSetDOS(out, deflater, 0, true);
}, "java.io.OutputStream,java.util.zip.Deflater");
});
;//5.0.1-v7 Fri Nov 21 04:39:22 CST 2025
Clazz_load(["java.io.OutputStream"], "java.io.FilterOutputStream", null, function(){
var c$ = Clazz_decorateAsClass(function(){
this.out = null;
Clazz_instantialize(this, arguments);}, java.io, "FilterOutputStream", java.io.OutputStream);
Clazz_defineMethod(c$, "jzSetFOS", 
function(out){
this.out = out;
}, "java.io.OutputStream");
Clazz_defineMethod(c$, "writeByteAsInt", 
function(b){
this.out.writeByteAsInt(b);
}, "~N");
Clazz_defineMethod(c$, "write", 
function(b, off, len){
if ((off | len | (b.length - (len + off)) | (off + len)) < 0) throw  new IndexOutOfBoundsException();
for (var i = 0; i < len; i++) {
this.writeByteAsInt(b[off + i]);
}
}, "~A,~N,~N");
Clazz_defineMethod(c$, "flush", 
function(){
this.out.flush();
});
Clazz_defineMethod(c$, "close", 
function(){
try {
this.flush();
} catch (ignored) {
if (Clazz_exceptionOf(ignored,"java.io.IOException")){
} else {
throw ignored;
}
}
this.out.close();
});
});
;//5.0.1-v7 Fri Nov 21 04:39:22 CST 2025
Clazz_declarePackage("com.jcraft.jzlib");
Clazz_load(["com.jcraft.jzlib.Checksum"], "com.jcraft.jzlib.Adler32", null, function(){
var c$ = Clazz_decorateAsClass(function(){
this.s1 = 1;
this.s2 = 0;
this.b1 = null;
Clazz_instantialize(this, arguments);}, com.jcraft.jzlib, "Adler32", null, com.jcraft.jzlib.Checksum);
Clazz_prepareFields (c$, function(){
this.b1 =  Clazz_newByteArray (1, 0);
});
Clazz_overrideMethod(c$, "resetLong", 
function(init){
this.s1 = init & 0xffff;
this.s2 = (init >> 16) & 0xffff;
}, "~N");
Clazz_overrideMethod(c$, "reset", 
function(){
this.s1 = 1;
this.s2 = 0;
});
Clazz_overrideMethod(c$, "getValue", 
function(){
return ((this.s2 << 16) | this.s1);
});
Clazz_overrideMethod(c$, "update", 
function(buf, index, len){
if (len == 1) {
this.s1 += buf[index++] & 0xff;
this.s2 += this.s1;
this.s1 %= 65521;
this.s2 %= 65521;
return;
}var len1 = Clazz_doubleToInt(len / 5552);
var len2 = len % 5552;
while (len1-- > 0) {
var k = 5552;
len -= k;
while (k-- > 0) {
this.s1 += buf[index++] & 0xff;
this.s2 += this.s1;
}
this.s1 %= 65521;
this.s2 %= 65521;
}
var k = len2;
len -= k;
while (k-- > 0) {
this.s1 += buf[index++] & 0xff;
this.s2 += this.s1;
}
this.s1 %= 65521;
this.s2 %= 65521;
}, "~A,~N,~N");
Clazz_overrideMethod(c$, "updateByteAsInt", 
function(b){
this.b1[0] = b;
this.update(this.b1, 0, 1);
}, "~N");
});
;//5.0.1-v7 Fri Nov 21 12:35:46 CST 2025
Clazz_declarePackage("com.jcraft.jzlib");
(function(){
var c$ = Clazz_decorateAsClass(function(){
this.dyn_tree = null;
this.max_code = 0;
this.stat_desc = null;
Clazz_instantialize(this, arguments);}, com.jcraft.jzlib, "Tree", null);
c$.d_code = Clazz_defineMethod(c$, "d_code", 
function(dist){
return ((dist) < 256 ? com.jcraft.jzlib.Tree._dist_code[dist] : com.jcraft.jzlib.Tree._dist_code[256 + ((dist) >>> 7)]);
}, "~N");
Clazz_defineMethod(c$, "gen_bitlen", 
function(s){
var tree = this.dyn_tree;
var stree = this.stat_desc.static_tree;
var extra = this.stat_desc.extra_bits;
var base = this.stat_desc.extra_base;
var max_length = this.stat_desc.max_length;
var h;
var n;
var m;
var bits;
var xbits;
var f;
var overflow = 0;
for (bits = 0; bits <= 15; bits++) s.bl_count[bits] = 0;

tree[s.heap[s.heap_max] * 2 + 1] = 0;
for (h = s.heap_max + 1; h < 573; h++) {
n = s.heap[h];
bits = tree[tree[n * 2 + 1] * 2 + 1] + 1;
if (bits > max_length) {
bits = max_length;
overflow++;
}tree[n * 2 + 1] = bits;
if (n > this.max_code) continue;
s.bl_count[bits]++;
xbits = 0;
if (n >= base) xbits = extra[n - base];
f = tree[n * 2];
s.opt_len += f * (bits + xbits);
if (stree != null) s.static_len += f * (stree[n * 2 + 1] + xbits);
}
if (overflow == 0) return;
do {
bits = max_length - 1;
while (s.bl_count[bits] == 0) bits--;

s.bl_count[bits]--;
s.bl_count[bits + 1] += 2;
s.bl_count[max_length]--;
overflow -= 2;
} while (overflow > 0);
for (bits = max_length; bits != 0; bits--) {
n = s.bl_count[bits];
while (n != 0) {
m = s.heap[--h];
if (m > this.max_code) continue;
if (tree[m * 2 + 1] != bits) {
s.opt_len += (bits - tree[m * 2 + 1]) * tree[m * 2];
tree[m * 2 + 1] = bits;
}n--;
}
}
}, "com.jcraft.jzlib.Deflate");
Clazz_defineMethod(c$, "build_tree", 
function(s){
var tree = this.dyn_tree;
var stree = this.stat_desc.static_tree;
var elems = this.stat_desc.elems;
var n;
var m;
var max_code = -1;
var node;
s.heap_len = 0;
s.heap_max = 573;
for (n = 0; n < elems; n++) {
if (tree[n * 2] != 0) {
s.heap[++s.heap_len] = max_code = n;
s.depth[n] = 0;
} else {
tree[n * 2 + 1] = 0;
}}
while (s.heap_len < 2) {
node = s.heap[++s.heap_len] = (max_code < 2 ? ++max_code : 0);
tree[node * 2] = 1;
s.depth[node] = 0;
s.opt_len--;
if (stree != null) s.static_len -= stree[node * 2 + 1];
}
this.max_code = max_code;
for (n = Clazz_doubleToInt(s.heap_len / 2); n >= 1; n--) s.pqdownheap(tree, n);

node = elems;
do {
n = s.heap[1];
s.heap[1] = s.heap[s.heap_len--];
s.pqdownheap(tree, 1);
m = s.heap[1];
s.heap[--s.heap_max] = n;
s.heap[--s.heap_max] = m;
tree[node * 2] = (tree[n * 2] + tree[m * 2]);
s.depth[node] = (Math.max(s.depth[n], s.depth[m]) + 1);
tree[n * 2 + 1] = tree[m * 2 + 1] = node;
s.heap[1] = node++;
s.pqdownheap(tree, 1);
} while (s.heap_len >= 2);
s.heap[--s.heap_max] = s.heap[1];
this.gen_bitlen(s);
com.jcraft.jzlib.Tree.gen_codes(tree, max_code, s.bl_count);
}, "com.jcraft.jzlib.Deflate");
c$.gen_codes = Clazz_defineMethod(c$, "gen_codes", 
function(tree, max_code, bl_count){
var code = 0;
var bits;
var n;
com.jcraft.jzlib.Tree.next_code[0] = 0;
for (bits = 1; bits <= 15; bits++) {
com.jcraft.jzlib.Tree.next_code[bits] = code = ((code + bl_count[bits - 1]) << 1);
}
for (n = 0; n <= max_code; n++) {
var len = tree[n * 2 + 1];
if (len == 0) continue;
tree[n * 2] = (com.jcraft.jzlib.Tree.bi_reverse(com.jcraft.jzlib.Tree.next_code[len]++, len));
}
}, "~A,~N,~A");
c$.bi_reverse = Clazz_defineMethod(c$, "bi_reverse", 
function(code, len){
var res = 0;
do {
res |= code & 1;
code >>>= 1;
res <<= 1;
} while (--len > 0);
return res >>> 1;
}, "~N,~N");
c$.extra_lbits =  Clazz_newIntArray(-1, [0, 0, 0, 0, 0, 0, 0, 0, 1, 1, 1, 1, 2, 2, 2, 2, 3, 3, 3, 3, 4, 4, 4, 4, 5, 5, 5, 5, 0]);
c$.extra_dbits =  Clazz_newIntArray(-1, [0, 0, 0, 0, 1, 1, 2, 2, 3, 3, 4, 4, 5, 5, 6, 6, 7, 7, 8, 8, 9, 9, 10, 10, 11, 11, 12, 12, 13, 13]);
c$.extra_blbits =  Clazz_newIntArray(-1, [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 2, 3, 7]);
c$.bl_order =  Clazz_newByteArray(-1, [16, 17, 18, 0, 8, 7, 9, 6, 10, 5, 11, 4, 12, 3, 13, 2, 14, 1, 15]);
c$._dist_code =  Clazz_newByteArray(-1, [0, 1, 2, 3, 4, 4, 5, 5, 6, 6, 6, 6, 7, 7, 7, 7, 8, 8, 8, 8, 8, 8, 8, 8, 9, 9, 9, 9, 9, 9, 9, 9, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 11, 11, 11, 11, 11, 11, 11, 11, 11, 11, 11, 11, 11, 11, 11, 11, 12, 12, 12, 12, 12, 12, 12, 12, 12, 12, 12, 12, 12, 12, 12, 12, 12, 12, 12, 12, 12, 12, 12, 12, 12, 12, 12, 12, 12, 12, 12, 12, 13, 13, 13, 13, 13, 13, 13, 13, 13, 13, 13, 13, 13, 13, 13, 13, 13, 13, 13, 13, 13, 13, 13, 13, 13, 13, 13, 13, 13, 13, 13, 13, 14, 14, 14, 14, 14, 14, 14, 14, 14, 14, 14, 14, 14, 14, 14, 14, 14, 14, 14, 14, 14, 14, 14, 14, 14, 14, 14, 14, 14, 14, 14, 14, 14, 14, 14, 14, 14, 14, 14, 14, 14, 14, 14, 14, 14, 14, 14, 14, 14, 14, 14, 14, 14, 14, 14, 14, 14, 14, 14, 14, 14, 14, 14, 14, 15, 15, 15, 15, 15, 15, 15, 15, 15, 15, 15, 15, 15, 15, 15, 15, 15, 15, 15, 15, 15, 15, 15, 15, 15, 15, 15, 15, 15, 15, 15, 15, 15, 15, 15, 15, 15, 15, 15, 15, 15, 15, 15, 15, 15, 15, 15, 15, 15, 15, 15, 15, 15, 15, 15, 15, 15, 15, 15, 15, 15, 15, 15, 15, 0, 0, 16, 17, 18, 18, 19, 19, 20, 20, 20, 20, 21, 21, 21, 21, 22, 22, 22, 22, 22, 22, 22, 22, 23, 23, 23, 23, 23, 23, 23, 23, 24, 24, 24, 24, 24, 24, 24, 24, 24, 24, 24, 24, 24, 24, 24, 24, 25, 25, 25, 25, 25, 25, 25, 25, 25, 25, 25, 25, 25, 25, 25, 25, 26, 26, 26, 26, 26, 26, 26, 26, 26, 26, 26, 26, 26, 26, 26, 26, 26, 26, 26, 26, 26, 26, 26, 26, 26, 26, 26, 26, 26, 26, 26, 26, 27, 27, 27, 27, 27, 27, 27, 27, 27, 27, 27, 27, 27, 27, 27, 27, 27, 27, 27, 27, 27, 27, 27, 27, 27, 27, 27, 27, 27, 27, 27, 27, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 29, 29, 29, 29, 29, 29, 29, 29, 29, 29, 29, 29, 29, 29, 29, 29, 29, 29, 29, 29, 29, 29, 29, 29, 29, 29, 29, 29, 29, 29, 29, 29, 29, 29, 29, 29, 29, 29, 29, 29, 29, 29, 29, 29, 29, 29, 29, 29, 29, 29, 29, 29, 29, 29, 29, 29, 29, 29, 29, 29, 29, 29, 29, 29]);
c$._length_code =  Clazz_newByteArray(-1, [0, 1, 2, 3, 4, 5, 6, 7, 8, 8, 9, 9, 10, 10, 11, 11, 12, 12, 12, 12, 13, 13, 13, 13, 14, 14, 14, 14, 15, 15, 15, 15, 16, 16, 16, 16, 16, 16, 16, 16, 17, 17, 17, 17, 17, 17, 17, 17, 18, 18, 18, 18, 18, 18, 18, 18, 19, 19, 19, 19, 19, 19, 19, 19, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 21, 21, 21, 21, 21, 21, 21, 21, 21, 21, 21, 21, 21, 21, 21, 21, 22, 22, 22, 22, 22, 22, 22, 22, 22, 22, 22, 22, 22, 22, 22, 22, 23, 23, 23, 23, 23, 23, 23, 23, 23, 23, 23, 23, 23, 23, 23, 23, 24, 24, 24, 24, 24, 24, 24, 24, 24, 24, 24, 24, 24, 24, 24, 24, 24, 24, 24, 24, 24, 24, 24, 24, 24, 24, 24, 24, 24, 24, 24, 24, 25, 25, 25, 25, 25, 25, 25, 25, 25, 25, 25, 25, 25, 25, 25, 25, 25, 25, 25, 25, 25, 25, 25, 25, 25, 25, 25, 25, 25, 25, 25, 25, 26, 26, 26, 26, 26, 26, 26, 26, 26, 26, 26, 26, 26, 26, 26, 26, 26, 26, 26, 26, 26, 26, 26, 26, 26, 26, 26, 26, 26, 26, 26, 26, 27, 27, 27, 27, 27, 27, 27, 27, 27, 27, 27, 27, 27, 27, 27, 27, 27, 27, 27, 27, 27, 27, 27, 27, 27, 27, 27, 27, 27, 27, 27, 28]);
c$.base_length =  Clazz_newIntArray(-1, [0, 1, 2, 3, 4, 5, 6, 7, 8, 10, 12, 14, 16, 20, 24, 28, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 0]);
c$.base_dist =  Clazz_newIntArray(-1, [0, 1, 2, 3, 4, 6, 8, 12, 16, 24, 32, 48, 64, 96, 128, 192, 256, 384, 512, 768, 1024, 1536, 2048, 3072, 4096, 6144, 8192, 12288, 16384, 24576]);
c$.next_code =  Clazz_newShortArray (16, 0);
})();
;//5.0.1-v7 Fri Nov 21 12:35:46 CST 2025
Clazz_declarePackage("com.jcraft.jzlib");
Clazz_load(["com.jcraft.jzlib.Checksum"], "com.jcraft.jzlib.CRC32", null, function(){
var c$ = Clazz_decorateAsClass(function(){
this.crc = 0;
this.b1 = null;
Clazz_instantialize(this, arguments);}, com.jcraft.jzlib, "CRC32", null, com.jcraft.jzlib.Checksum);
Clazz_prepareFields (c$, function(){
this.b1 =  Clazz_newByteArray (1, 0);
});
Clazz_overrideMethod(c$, "update", 
function(buf, index, len){
var c = ~this.crc;
while (--len >= 0) c = com.jcraft.jzlib.CRC32.crc_table[(c ^ buf[index++]) & 0xff] ^ (c >>> 8);

this.crc = ~c;
}, "~A,~N,~N");
Clazz_overrideMethod(c$, "reset", 
function(){
this.crc = 0;
});
Clazz_overrideMethod(c$, "resetLong", 
function(vv){
this.crc = (vv & 0xffffffff);
}, "~N");
Clazz_overrideMethod(c$, "getValue", 
function(){
return this.crc & 0xffffffff;
});
Clazz_overrideMethod(c$, "updateByteAsInt", 
function(b){
this.b1[0] = b;
this.update(this.b1, 0, 1);
}, "~N");
c$.crc_table =  Clazz_newIntArray(-1, [0, 1996959894, -301047508, -1727442502, 124634137, 1886057615, -379345611, -1637575261, 249268274, 2044508324, -522852066, -1747789432, 162941995, 2125561021, -407360249, -1866523247, 498536548, 1789927666, -205950648, -2067906082, 450548861, 1843258603, -187386543, -2083289657, 325883990, 1684777152, -43845254, -1973040660, 335633487, 1661365465, -99664541, -1928851979, 997073096, 1281953886, -715111964, -1570279054, 1006888145, 1258607687, -770865667, -1526024853, 901097722, 1119000684, -608450090, -1396901568, 853044451, 1172266101, -589951537, -1412350631, 651767980, 1373503546, -925412992, -1076862698, 565507253, 1454621731, -809855591, -1195530993, 671266974, 1594198024, -972236366, -1324619484, 795835527, 1483230225, -1050600021, -1234817731, 1994146192, 31158534, -1731059524, -271249366, 1907459465, 112637215, -1614814043, -390540237, 2013776290, 251722036, -1777751922, -519137256, 2137656763, 141376813, -1855689577, -429695999, 1802195444, 476864866, -2056965928, -228458418, 1812370925, 453092731, -2113342271, -183516073, 1706088902, 314042704, -1950435094, -54949764, 1658658271, 366619977, -1932296973, -69972891, 1303535960, 984961486, -1547960204, -725929758, 1256170817, 1037604311, -1529756563, -740887301, 1131014506, 879679996, -1385723834, -631195440, 1141124467, 855842277, -1442165665, -586318647, 1342533948, 654459306, -1106571248, -921952122, 1466479909, 544179635, -1184443383, -832445281, 1591671054, 702138776, -1328506846, -942167884, 1504918807, 783551873, -1212326853, -1061524307, -306674912, -1698712650, 62317068, 1957810842, -355121351, -1647151185, 81470997, 1943803523, -480048366, -1805370492, 225274430, 2053790376, -468791541, -1828061283, 167816743, 2097651377, -267414716, -2029476910, 503444072, 1762050814, -144550051, -2140837941, 426522225, 1852507879, -19653770, -1982649376, 282753626, 1742555852, -105259153, -1900089351, 397917763, 1622183637, -690576408, -1580100738, 953729732, 1340076626, -776247311, -1497606297, 1068828381, 1219638859, -670225446, -1358292148, 906185462, 1090812512, -547295293, -1469587627, 829329135, 1181335161, -882789492, -1134132454, 628085408, 1382605366, -871598187, -1156888829, 570562233, 1426400815, -977650754, -1296233688, 733239954, 1555261956, -1026031705, -1244606671, 752459403, 1541320221, -1687895376, -328994266, 1969922972, 40735498, -1677130071, -351390145, 1913087877, 83908371, -1782625662, -491226604, 2075208622, 213261112, -1831694693, -438977011, 2094854071, 198958881, -2032938284, -237706686, 1759359992, 534414190, -2118248755, -155638181, 1873836001, 414664567, -2012718362, -15766928, 1711684554, 285281116, -1889165569, -127750551, 1634467795, 376229701, -1609899400, -686959890, 1308918612, 956543938, -1486412191, -799009033, 1231636301, 1047427035, -1362007478, -640263460, 1088359270, 936918000, -1447252397, -558129467, 1202900863, 817233897, -1111625188, -893730166, 1404277552, 615818150, -1160759803, -841546093, 1423857449, 601450431, -1285129682, -1000256840, 1567103746, 711928724, -1274298825, -1022587231, 1510334235, 755167117]);
});
;//5.0.1-v7 Fri Nov 21 12:35:46 CST 2025
Clazz_declarePackage("com.jcraft.jzlib");
Clazz_load(null, "com.jcraft.jzlib.GZIPHeader", ["com.jcraft.jzlib.ZStream"], function(){
var c$ = Clazz_decorateAsClass(function(){
this.text = false;
this.fhcrc = false;
this.time = 0;
this.xflags = 0;
this.os = 255;
this.extra = null;
this.name = null;
this.comment = null;
this.hcrc = 0;
this.crc = 0;
this.done = false;
this.mtime = 0;
Clazz_instantialize(this, arguments);}, com.jcraft.jzlib, "GZIPHeader", null, Cloneable);
Clazz_defineMethod(c$, "setModifiedTime", 
function(mtime){
this.mtime = mtime;
}, "~N");
Clazz_defineMethod(c$, "getModifiedTime", 
function(){
return this.mtime;
});
Clazz_defineMethod(c$, "setOS", 
function(os){
if ((0 <= os && os <= 13) || os == 255) this.os = os;
 else throw  new IllegalArgumentException("os: " + os);
}, "~N");
Clazz_defineMethod(c$, "getOS", 
function(){
return this.os;
});
Clazz_defineMethod(c$, "setName", 
function(name){
this.name = com.jcraft.jzlib.ZStream.getBytes(name);
}, "~S");
Clazz_defineMethod(c$, "getName", 
function(){
if (this.name == null) return "";
try {
return  String.instantialize(this.name, "ISO-8859-1");
} catch (e) {
if (Clazz_exceptionOf(e,"java.io.UnsupportedEncodingException")){
throw  new InternalError(e.toString());
} else {
throw e;
}
}
});
Clazz_defineMethod(c$, "setComment", 
function(comment){
this.comment = com.jcraft.jzlib.ZStream.getBytes(comment);
}, "~S");
Clazz_defineMethod(c$, "getComment", 
function(){
if (this.comment == null) return "";
try {
return  String.instantialize(this.comment, "ISO-8859-1");
} catch (e) {
if (Clazz_exceptionOf(e,"java.io.UnsupportedEncodingException")){
throw  new InternalError(e.toString());
} else {
throw e;
}
}
});
Clazz_defineMethod(c$, "setCRC", 
function(crc){
this.crc = crc;
}, "~N");
Clazz_defineMethod(c$, "getCRC", 
function(){
return this.crc;
});
Clazz_defineMethod(c$, "put", 
function(d){
var flag = 0;
if (this.text) {
flag |= 1;
}if (this.fhcrc) {
flag |= 2;
}if (this.extra != null) {
flag |= 4;
}if (this.name != null) {
flag |= 8;
}if (this.comment != null) {
flag |= 16;
}var xfl = 0;
if (d.level == 1) {
xfl |= 4;
} else if (d.level == 9) {
xfl |= 2;
}d.put_short(0x8b1f);
d.put_byteB(8);
d.put_byteB(flag);
d.put_byteB(this.mtime);
d.put_byteB((this.mtime >> 8));
d.put_byteB((this.mtime >> 16));
d.put_byteB((this.mtime >> 24));
d.put_byteB(xfl);
d.put_byteB(this.os);
if (this.extra != null) {
d.put_byteB(this.extra.length);
d.put_byteB((this.extra.length >> 8));
d.put_byte(this.extra, 0, this.extra.length);
}if (this.name != null) {
d.put_byte(this.name, 0, this.name.length);
d.put_byteB(0);
}if (this.comment != null) {
d.put_byte(this.comment, 0, this.comment.length);
d.put_byteB(0);
}}, "com.jcraft.jzlib.Deflate");
Clazz_defineMethod(c$, "clone", 
function(){
var gheader = Clazz_superCall(this, com.jcraft.jzlib.GZIPHeader, "clone", []);
var tmp;
if (gheader.extra != null) {
tmp =  Clazz_newByteArray (gheader.extra.length, 0);
System.arraycopy(gheader.extra, 0, tmp, 0, tmp.length);
gheader.extra = tmp;
}if (gheader.name != null) {
tmp =  Clazz_newByteArray (gheader.name.length, 0);
System.arraycopy(gheader.name, 0, tmp, 0, tmp.length);
gheader.name = tmp;
}if (gheader.comment != null) {
tmp =  Clazz_newByteArray (gheader.comment.length, 0);
System.arraycopy(gheader.comment, 0, tmp, 0, tmp.length);
gheader.comment = tmp;
}return gheader;
});
});
;//5.0.1-v7 Fri Nov 21 12:35:46 CST 2025
Clazz_declarePackage("com.jcraft.jzlib");
Clazz_load(["com.jcraft.jzlib.Tree"], "com.jcraft.jzlib.StaticTree", null, function(){
var c$ = Clazz_decorateAsClass(function(){
this.static_tree = null;
this.extra_bits = null;
this.extra_base = 0;
this.elems = 0;
this.max_length = 0;
Clazz_instantialize(this, arguments);}, com.jcraft.jzlib, "StaticTree", null);
Clazz_makeConstructor(c$, 
function(static_tree, extra_bits, extra_base, elems, max_length){
this.static_tree = static_tree;
this.extra_bits = extra_bits;
this.extra_base = extra_base;
this.elems = elems;
this.max_length = max_length;
}, "~A,~A,~N,~N,~N");
c$.static_ltree =  Clazz_newShortArray(-1, [12, 8, 140, 8, 76, 8, 204, 8, 44, 8, 172, 8, 108, 8, 236, 8, 28, 8, 156, 8, 92, 8, 220, 8, 60, 8, 188, 8, 124, 8, 252, 8, 2, 8, 130, 8, 66, 8, 194, 8, 34, 8, 162, 8, 98, 8, 226, 8, 18, 8, 146, 8, 82, 8, 210, 8, 50, 8, 178, 8, 114, 8, 242, 8, 10, 8, 138, 8, 74, 8, 202, 8, 42, 8, 170, 8, 106, 8, 234, 8, 26, 8, 154, 8, 90, 8, 218, 8, 58, 8, 186, 8, 122, 8, 250, 8, 6, 8, 134, 8, 70, 8, 198, 8, 38, 8, 166, 8, 102, 8, 230, 8, 22, 8, 150, 8, 86, 8, 214, 8, 54, 8, 182, 8, 118, 8, 246, 8, 14, 8, 142, 8, 78, 8, 206, 8, 46, 8, 174, 8, 110, 8, 238, 8, 30, 8, 158, 8, 94, 8, 222, 8, 62, 8, 190, 8, 126, 8, 254, 8, 1, 8, 129, 8, 65, 8, 193, 8, 33, 8, 161, 8, 97, 8, 225, 8, 17, 8, 145, 8, 81, 8, 209, 8, 49, 8, 177, 8, 113, 8, 241, 8, 9, 8, 137, 8, 73, 8, 201, 8, 41, 8, 169, 8, 105, 8, 233, 8, 25, 8, 153, 8, 89, 8, 217, 8, 57, 8, 185, 8, 121, 8, 249, 8, 5, 8, 133, 8, 69, 8, 197, 8, 37, 8, 165, 8, 101, 8, 229, 8, 21, 8, 149, 8, 85, 8, 213, 8, 53, 8, 181, 8, 117, 8, 245, 8, 13, 8, 141, 8, 77, 8, 205, 8, 45, 8, 173, 8, 109, 8, 237, 8, 29, 8, 157, 8, 93, 8, 221, 8, 61, 8, 189, 8, 125, 8, 253, 8, 19, 9, 275, 9, 147, 9, 403, 9, 83, 9, 339, 9, 211, 9, 467, 9, 51, 9, 307, 9, 179, 9, 435, 9, 115, 9, 371, 9, 243, 9, 499, 9, 11, 9, 267, 9, 139, 9, 395, 9, 75, 9, 331, 9, 203, 9, 459, 9, 43, 9, 299, 9, 171, 9, 427, 9, 107, 9, 363, 9, 235, 9, 491, 9, 27, 9, 283, 9, 155, 9, 411, 9, 91, 9, 347, 9, 219, 9, 475, 9, 59, 9, 315, 9, 187, 9, 443, 9, 123, 9, 379, 9, 251, 9, 507, 9, 7, 9, 263, 9, 135, 9, 391, 9, 71, 9, 327, 9, 199, 9, 455, 9, 39, 9, 295, 9, 167, 9, 423, 9, 103, 9, 359, 9, 231, 9, 487, 9, 23, 9, 279, 9, 151, 9, 407, 9, 87, 9, 343, 9, 215, 9, 471, 9, 55, 9, 311, 9, 183, 9, 439, 9, 119, 9, 375, 9, 247, 9, 503, 9, 15, 9, 271, 9, 143, 9, 399, 9, 79, 9, 335, 9, 207, 9, 463, 9, 47, 9, 303, 9, 175, 9, 431, 9, 111, 9, 367, 9, 239, 9, 495, 9, 31, 9, 287, 9, 159, 9, 415, 9, 95, 9, 351, 9, 223, 9, 479, 9, 63, 9, 319, 9, 191, 9, 447, 9, 127, 9, 383, 9, 255, 9, 511, 9, 0, 7, 64, 7, 32, 7, 96, 7, 16, 7, 80, 7, 48, 7, 112, 7, 8, 7, 72, 7, 40, 7, 104, 7, 24, 7, 88, 7, 56, 7, 120, 7, 4, 7, 68, 7, 36, 7, 100, 7, 20, 7, 84, 7, 52, 7, 116, 7, 3, 8, 131, 8, 67, 8, 195, 8, 35, 8, 163, 8, 99, 8, 227, 8]);
c$.static_dtree =  Clazz_newShortArray(-1, [0, 5, 16, 5, 8, 5, 24, 5, 4, 5, 20, 5, 12, 5, 28, 5, 2, 5, 18, 5, 10, 5, 26, 5, 6, 5, 22, 5, 14, 5, 30, 5, 1, 5, 17, 5, 9, 5, 25, 5, 5, 5, 21, 5, 13, 5, 29, 5, 3, 5, 19, 5, 11, 5, 27, 5, 7, 5, 23, 5]);
c$.static_l_desc =  new com.jcraft.jzlib.StaticTree(com.jcraft.jzlib.StaticTree.static_ltree, com.jcraft.jzlib.Tree.extra_lbits, 257, 286, 15);
c$.static_d_desc =  new com.jcraft.jzlib.StaticTree(com.jcraft.jzlib.StaticTree.static_dtree, com.jcraft.jzlib.Tree.extra_dbits, 0, 30, 15);
c$.static_bl_desc =  new com.jcraft.jzlib.StaticTree(null, com.jcraft.jzlib.Tree.extra_blbits, 0, 19, 7);
});
;//5.0.1-v7 Fri Nov 21 12:35:46 CST 2025
Clazz_declarePackage("com.jcraft.jzlib");
Clazz_declareInterface(com.jcraft.jzlib, "Checksum");
;//5.0.1-v7 Fri Nov 21 12:35:46 CST 2025
})(Clazz
,Clazz.getClassName
,Clazz.newLongArray
,Clazz.doubleToByte
,Clazz.doubleToInt
,Clazz.doubleToLong
,Clazz.declarePackage
,Clazz.instanceOf
,Clazz.load
,Clazz.instantialize
,Clazz.decorateAsClass
,Clazz.floatToInt
,Clazz.floatToLong
,Clazz.makeConstructor
,Clazz.defineEnumConstant
,Clazz.exceptionOf
,Clazz.newIntArray
,Clazz.newFloatArray
,Clazz.declareType
,Clazz.prepareFields
,Clazz.superConstructor
,Clazz.newByteArray
,Clazz.declareInterface
,Clazz.newShortArray
,Clazz.innerTypeInstance
,Clazz.isClassDefined
,Clazz.prepareCallback
,Clazz.newArray
,Clazz.castNullAs
,Clazz.floatToShort
,Clazz.superCall
,Clazz.decorateAsType
,Clazz.newBooleanArray
,Clazz.newCharArray
,Clazz.implementOf
,Clazz.newDoubleArray
,Clazz.overrideConstructor
,Clazz.clone
,Clazz.doubleToShort
,Clazz.getInheritedLevel
,Clazz.getParamsType
,Clazz.isAF
,Clazz.isAB
,Clazz.isAI
,Clazz.isAS
,Clazz.isASS
,Clazz.isAP
,Clazz.isAFloat
,Clazz.isAII
,Clazz.isAFF
,Clazz.isAFFF
,Clazz.tryToSearchAndExecute
,Clazz.getStackTrace
,Clazz.inheritArgs
,Clazz.alert
,Clazz.defineMethod
,Clazz.overrideMethod
,Clazz.declareAnonymous
//,Clazz.checkPrivateMethod
,Clazz.cloneFinals
);
