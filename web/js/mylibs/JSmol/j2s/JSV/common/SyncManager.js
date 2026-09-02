Clazz.declarePackage("JSV.common");
Clazz.load(null, "JSV.common.SyncManager", ["JU.PT", "$.SB", "JSV.common.PanelNode", "JU.Logger"], function(){
var c$ = Clazz.declareType(JSV.common, "SyncManager", null);
c$.syncToJmol = Clazz.defineMethod(c$, "syncToJmol", 
function(vwr, pi){
vwr.peakInfoModelSentToJmol = pi.getModel();
vwr.si.syncToJmol(JSV.common.SyncManager.jmolSelect(vwr.pd(), pi));
}, "JSV.common.JSViewer,JSV.common.PeakInfo");
c$.syncFromJmol = Clazz.defineMethod(c$, "syncFromJmol", 
function(vwr, peakScript){
if (peakScript.equals("TEST")) peakScript = JSV.common.SyncManager.testScript;
if (peakScript.indexOf("<PeakData") < 0) {
if (peakScript.startsWith("JSVSTR:")) {
vwr.si.syncToJmol(peakScript);
return;
}vwr.runScriptNow(peakScript);
if (peakScript.indexOf("#SYNC_PEAKS") >= 0) JSV.common.SyncManager.syncToJmolPeaksAfterSyncScript(vwr);
return;
}var sourceID = JU.PT.getQuotedAttribute(peakScript, "sourceID");
var type;
var model;
var file;
var jmolSource;
var index;
var atomKey;
if (sourceID == null) {
file = JU.PT.getQuotedAttribute(peakScript, "file");
index = JU.PT.getQuotedAttribute(peakScript, "index");
if (file == null || index == null) return;
file = JU.PT.rep(file, "#molfile", "");
model = JU.PT.getQuotedAttribute(peakScript, "model");
jmolSource = JU.PT.getQuotedAttribute(peakScript, "src");
var modelSent = (jmolSource != null && jmolSource.startsWith("Jmol") ? null : vwr.peakInfoModelSentToJmol);
if (model != null && modelSent != null && !model.equals(modelSent)) {
JU.Logger.info("JSV ignoring model " + model + "; should be " + modelSent);
return;
}vwr.peakInfoModelSentToJmol = null;
if (vwr.panelNodes.size() == 0 || !vwr.checkFileAlreadyLoaded(file)) {
JU.Logger.info("file " + file + " not found -- JSViewer closing all and reopening");
vwr.si.siSyncLoad(file);
}type = JU.PT.getQuotedAttribute(peakScript, "type");
atomKey = null;
} else {
file = null;
index = model = sourceID;
atomKey = "," + JU.PT.getQuotedAttribute(peakScript, "atom") + ",";
type = "ID";
jmolSource = sourceID;
}var pi = JSV.common.SyncManager.selectPanelByPeak(vwr, file, index, atomKey);
var pd = vwr.pd();
pd.selectSpectrum(file, type, model, true);
vwr.si.siSendPanelChange();
pd.addPeakHighlight(pi);
vwr.repaint(true);
if (jmolSource == null || (pi != null && pi.getAtoms() != null)) vwr.si.syncToJmol(JSV.common.SyncManager.jmolSelect(vwr.pd(), pi));
}, "JSV.common.JSViewer,~S");
c$.syncToJmolPeaksAfterSyncScript = Clazz.defineMethod(c$, "syncToJmolPeaksAfterSyncScript", 
function(vwr){
var source = vwr.currentSource;
if (source == null) return;
try {
var file = "file=" + JU.PT.esc(source.getFilePath());
var spectra = source.getSpectra();
var peaks = spectra.get(spectra.size() - 1).getPeakList();
var sb =  new JU.SB();
sb.append("[");
var n = peaks.size();
for (var i = 0; i < n; i++) {
var s = peaks.get(i).toString();
s = s + " " + file;
sb.append(JU.PT.esc(s));
if (i > 0) sb.append(",");
}
sb.append("]");
vwr.si.syncToJmol("Peaks: " + sb);
} catch (e) {
if (Clazz.exceptionOf(e, Exception)){
} else {
throw e;
}
}
}, "JSV.common.JSViewer");
c$.selectPanelByPeak = Clazz.defineMethod(c$, "selectPanelByPeak", 
function(vwr, file, index, atomKey){
var panelNodes = vwr.panelNodes;
if (panelNodes == null) return null;
var pi = null;
for (var i = panelNodes.size(); --i >= 0; ) panelNodes.get(i).pd().addPeakHighlight(null);

pi = vwr.pd().selectPeakByFileIndex(file, index, atomKey);
if (pi != null) {
vwr.setNode(JSV.common.PanelNode.findNode(vwr.selectedPanel, panelNodes));
} else {
for (var i = panelNodes.size(); --i >= 0; ) {
var node = panelNodes.get(i);
if ((pi = node.pd().selectPeakByFileIndex(file, index, atomKey)) != null) {
vwr.setNode(node);
break;
}}
}return pi;
}, "JSV.common.JSViewer,~S,~S,~S");
c$.jmolSelect = Clazz.defineMethod(c$, "jmolSelect", 
function(pd, pi){
var script = ("IR".equals(pi.getType()) || "RAMAN".equals(pi.getType()) ? "vibration ON; selectionHalos OFF;" : "vibration OFF; selectionhalos " + (pi.getAtoms() == null ? "OFF" : "ON"));
return "Select: " + pi + " script=\"" + script + " \" sourceID=\"" + pd.getSpectrum().sourceID + "\"";
}, "JSV.common.PanelData,JSV.common.PeakInfo");
c$.testScript = "<PeakData  index=\"1\" title=\"\" model=\"~1.1\" type=\"1HNMR\" xMin=\"3.2915\" xMax=\"3.2965\" atoms=\"15,16,17,18,19,20\" multiplicity=\"\" integral=\"1\"> src=\"JPECVIEW\" file=\"http://SIMULATION/$caffeine\"";
});
;//5.0.1-v7 Mon Aug 17 10:07:10 MDT 2026
