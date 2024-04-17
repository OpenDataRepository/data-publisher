Clazz.declarePackage("JS");
Clazz.load(null, "JS.SymmetryInfo", ["JU.PT", "JS.SpaceGroup", "$.SymmetryOperation", "JU.SimpleUnitCell"], function(){
var c$ = Clazz.decorateAsClass(function(){
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
this.intlTableNoFull = null;
this.spaceGroupIndex = 0;
this.spaceGroupF2C = null;
this.spaceGroupF2CTitle = null;
this.spaceGroupF2CParams = null;
this.strSUPERCELL = null;
this.sgDerived = null;
this.isActive = true;
Clazz.instantialize(this, arguments);}, JS, "SymmetryInfo", null);
/*LV!1824 unnec constructor*/Clazz.defineMethod(c$, "setSymmetryInfo", 
function(modelInfo, unitCellParams, sg){
var symmetryCount;
if (sg == null) {
this.spaceGroupIndex = (modelInfo.get("spaceGroupIndex")).intValue();
this.cellRange = modelInfo.get("unitCellRange");
this.sgName = modelInfo.get("spaceGroup");
this.spaceGroupF2C = modelInfo.get("f2c");
this.spaceGroupF2CTitle = modelInfo.get("f2cTitle");
this.spaceGroupF2CParams = modelInfo.get("f2cParams");
this.sgTitle = modelInfo.get("spaceGroupTitle");
this.strSUPERCELL = modelInfo.get("supercell");
if (this.sgName == null || this.sgName === "") this.sgName = "spacegroup unspecified";
this.intlTableNo = modelInfo.get("intlTableNo");
this.intlTableNoFull = modelInfo.get("intlTableNoFull");
var s = modelInfo.get("latticeType");
this.latticeType = (s == null ? 'P' : s.charAt(0));
symmetryCount = modelInfo.containsKey("symmetryCount") ? (modelInfo.get("symmetryCount")).intValue() : 0;
this.symmetryOperations = modelInfo.remove("symmetryOps");
this.coordinatesAreFractional = modelInfo.containsKey("coordinatesAreFractional") ? (modelInfo.get("coordinatesAreFractional")).booleanValue() : false;
this.isMultiCell = (this.coordinatesAreFractional && this.symmetryOperations != null);
this.infoStr = "Spacegroup: " + this.sgName;
} else {
this.cellRange = null;
this.sgName = sg.getName();
this.intlTableNoFull = sg.intlTableNumberFull;
this.intlTableNo = sg.intlTableNumber;
this.latticeType = sg.latticeType;
symmetryCount = sg.getOperationCount();
this.symmetryOperations = sg.finalOperations;
this.coordinatesAreFractional = true;
this.infoStr = "Spacegroup: " + this.sgName;
}if (this.symmetryOperations != null) {
var c = "";
var s = "\nNumber of symmetry operations: " + (symmetryCount == 0 ? 1 : symmetryCount) + "\nSymmetry Operations:";
for (var i = 0; i < symmetryCount; i++) {
var op = this.symmetryOperations[i];
s += "\n" + op.fixMagneticXYZ(op, op.xyz, true);
if (op.isCenteringOp) c += " (" + JU.PT.rep(JU.PT.replaceAllCharacters(op.xyz, "xyz", "0"), "0+", "") + ")";
}
if (c.length > 0) this.infoStr += "\nCentering: " + c;
this.infoStr += s;
this.infoStr += "\n";
}if (unitCellParams == null) unitCellParams = modelInfo.get("unitCellParams");
unitCellParams = (JU.SimpleUnitCell.isValid(unitCellParams) ? unitCellParams : null);
if (unitCellParams == null) {
this.coordinatesAreFractional = false;
this.symmetryOperations = null;
this.cellRange = null;
this.infoStr = "";
modelInfo.remove("unitCellParams");
}return unitCellParams;
}, "java.util.Map,~A,JS.SpaceGroup");
Clazz.defineMethod(c$, "getAdditionalOperations", 
function(){
if (this.additionalOperations == null && this.symmetryOperations != null) {
this.additionalOperations = JS.SymmetryOperation.getAdditionalOperations(this.symmetryOperations);
}return this.additionalOperations;
});
Clazz.defineMethod(c$, "getDerivedSpaceGroup", 
function(){
if (this.sgDerived == null) {
this.sgDerived = JS.SpaceGroup.getSpaceGroupFromIndex(this.spaceGroupIndex);
}return this.sgDerived;
});
Clazz.defineMethod(c$, "setIsActiveCell", 
function(TF){
return (this.isActive != TF && (this.isActive = TF) == true);
}, "~B");
Clazz.defineMethod(c$, "getSpaceGroupTitle", 
function(){
return (this.isActive && this.spaceGroupF2CTitle != null ? this.spaceGroupF2CTitle : this.sgName.startsWith("cell=") ? this.sgName : this.sgTitle);
});
});
;//5.0.1-v2 Thu Mar 14 14:29:00 CDT 2024
