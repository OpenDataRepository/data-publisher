Clazz.declarePackage("JS");
Clazz.load(null, "JS.SymmetryInfo", ["JU.PT", "JS.SpaceGroup", "$.SymmetryOperation", "JU.SimpleUnitCell"], function(){
var c$ = Clazz.decorateAsClass(function(){
this.isCurrentCell = true;
this.displayName = null;
this.coordinatesAreFractional = false;
this.isMultiCell = false;
this.sgName = null;
this.sgTitle = null;
this.symmetryOperations = null;
this.additionalOperations = null;
this.infoStr = null;
this.cellRange = null;
this.latticeType = 'P';
this.intlTableNo = null;
this.intlTableJmolId = null;
this.spaceGroupIndex = 0;
this.itaNo = null;
this.isSpinSpaceGroup = false;
this.spaceGroupF2CTitle = null;
this.spaceGroupF2C = null;
this.spaceGroupF2CParams = null;
this.strSUPERCELL = null;
this.intlTableIndexNdotM = null;
this.intlTableTransform = null;
this.slop = 0;
this.fileSpaceGroup = null;
this.sgDerived = null;
Clazz.instantialize(this, arguments);}, JS, "SymmetryInfo", null);
Clazz.makeConstructor(c$, 
function(sg){
this.fileSpaceGroup = sg;
}, "JS.SpaceGroup");
Clazz.defineMethod(c$, "setSymmetryInfoFromModelkit", 
function(sg){
this.fileSpaceGroup = sg;
this.cellRange = null;
this.sgName = sg.getName();
this.intlTableJmolId = sg.jmolId;
this.intlTableNo = sg.itaNumber;
this.latticeType = sg.latticeType;
this.symmetryOperations = sg.finalOperations;
this.coordinatesAreFractional = true;
this.setInfo(sg.getOperationCount());
}, "JS.SpaceGroup");
Clazz.defineMethod(c$, "setSymmetryInfoFromFile", 
function(modelInfo, unitCellParams){
var index = modelInfo.remove("spaceGroupIndex");
this.spaceGroupIndex = (index == null ? 0 : index.intValue());
this.cellRange = modelInfo.remove("ML_unitCellRange");
this.sgName = modelInfo.get("spaceGroup");
this.isSpinSpaceGroup = (this.sgName != null && this.sgName.startsWith("spinSG:"));
this.spaceGroupF2C = modelInfo.remove("f2c");
this.spaceGroupF2CTitle = modelInfo.remove("f2cTitle");
this.spaceGroupF2CParams = modelInfo.remove("f2cParams");
this.sgTitle = modelInfo.remove("spaceGroupTitle");
this.strSUPERCELL = modelInfo.remove("supercell");
if (this.sgName == null || this.sgName === "") this.sgName = "spacegroup unspecified";
this.intlTableNo = modelInfo.get("intlTableNo");
this.intlTableIndexNdotM = modelInfo.get("intlTableIndex");
this.intlTableTransform = modelInfo.get("intlTableTransform");
this.intlTableJmolId = modelInfo.remove("intlTableJmolId");
var s = modelInfo.get("latticeType");
this.latticeType = (s == null ? 'P' : s.charAt(0));
this.symmetryOperations = modelInfo.remove("symOpsTemp");
this.coordinatesAreFractional = modelInfo.containsKey("coordinatesAreFractional") ? (modelInfo.get("coordinatesAreFractional")).booleanValue() : false;
this.isMultiCell = (this.coordinatesAreFractional && this.symmetryOperations != null);
if (unitCellParams == null) unitCellParams = modelInfo.get("unitCellParams");
unitCellParams = (JU.SimpleUnitCell.isValid(unitCellParams) ? unitCellParams : null);
if (unitCellParams == null) {
this.coordinatesAreFractional = false;
this.symmetryOperations = null;
this.cellRange = null;
this.infoStr = "";
modelInfo.remove("unitCellParams");
this.slop = NaN;
} else if (unitCellParams.length > 26) {
this.slop = unitCellParams[26];
}var symmetryCount = modelInfo.containsKey("symmetryCount") ? (modelInfo.get("symmetryCount")).intValue() : 0;
this.setInfo(symmetryCount);
return unitCellParams;
}, "java.util.Map,~A");
Clazz.defineMethod(c$, "setInfo", 
function(symmetryCount){
var info = "Spacegroup: " + this.sgName;
if (this.symmetryOperations != null) {
var c = "";
var s = "\nNumber of symmetry operations: " + (symmetryCount == 0 ? 1 : symmetryCount) + "\nSymmetry Operations:";
for (var i = 0; i < symmetryCount; i++) {
var op = this.symmetryOperations[i];
s += "\n" + op.fixMagneticXYZ(op, op.xyz);
if (op.isCenteringOp) c += " (" + JU.PT.rep(JU.PT.replaceAllCharacters(op.xyz, "xyz", "0"), "0+", "") + ")";
}
if (c.length > 0) info += "\nCentering: " + c;
info += s;
info += "\n";
}this.infoStr = info;
}, "~N");
Clazz.defineMethod(c$, "getAdditionalOperations", 
function(){
if (this.additionalOperations == null && this.symmetryOperations != null) {
this.additionalOperations = JS.SymmetryOperation.getAdditionalOperations(this.symmetryOperations, 0x73);
}return this.additionalOperations;
});
Clazz.defineMethod(c$, "getDerivedSpaceGroup", 
function(){
if (this.sgDerived == null) {
this.sgDerived = JS.SpaceGroup.getSpaceGroupFromIndex(this.spaceGroupIndex);
}return this.sgDerived;
});
Clazz.defineMethod(c$, "setIsCurrentCell", 
function(TF){
return (this.isCurrentCell != TF && (this.isCurrentCell = TF) == true);
}, "~B");
Clazz.defineMethod(c$, "getSpaceGroupTitle", 
function(){
return (this.isCurrentCell && this.spaceGroupF2CTitle != null ? this.spaceGroupF2CTitle : this.sgName.startsWith("cell=") ? this.sgName : this.sgTitle);
});
Clazz.defineMethod(c$, "getDisplayName", 
function(sym){
if (this.displayName == null) {
var isPolymer = sym.isPolymer();
var isSlab = sym.isSlab();
var sgName = (isPolymer ? "polymer" : isSlab ? "slab" : this.getSpaceGroupTitle());
if (sgName == null) return null;
if (sgName.startsWith("cell=!")) sgName = "cell=inverse[" + sgName.substring(6) + "]";
sgName = JU.PT.rep(sgName, ";0,0,0", "");
if (this.isSpinSpaceGroup) {
} else if (sgName.indexOf("#") < 0) {
var trm = this.intlTableTransform;
var intTab = this.intlTableIndexNdotM;
if (!isSlab && !isPolymer && intTab != null) {
if (trm != null) {
var pt = sgName.indexOf(trm);
if (pt >= 0) {
sgName = JU.PT.rep(sgName, "(" + trm + ")", "");
}if (intTab.indexOf(trm) < 0) {
pt = intTab.indexOf(".");
if (pt > 0) intTab = intTab.substring(0, pt);
intTab += ":" + trm;
}}sgName = (sgName.startsWith("0") ? "" : sgName.equals("unspecified!") ? "#" : sgName + " #") + intTab;
}}if (sgName.indexOf("-- [--]") >= 0) sgName = "";
this.displayName = sgName;
}return this.displayName;
}, "JS.Symmetry");
Clazz.defineMethod(c$, "getClegId", 
function(){
if (this.itaNo == null) {
this.itaNo = this.intlTableIndexNdotM;
if (this.itaNo == null || this.itaNo.indexOf(":") > 0) return this.itaNo;
var pt = this.itaNo.indexOf(".");
this.itaNo = (pt > 0 ? this.itaNo.substring(0, pt) : this.itaNo) + ":" + this.intlTableTransform;
}return this.itaNo;
});
Clazz.defineMethod(c$, "getFileSpaceGroup", 
function(){
return this.fileSpaceGroup;
});
});
;//5.0.1-v7 Mon Aug 17 10:07:10 MDT 2026
