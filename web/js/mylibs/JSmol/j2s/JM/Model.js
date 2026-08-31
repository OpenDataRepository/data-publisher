Clazz.declarePackage("JM");
Clazz.load(null, "JM.Model", ["java.util.Hashtable", "JU.AU", "$.BS", "$.M3", "$.P3", "$.SB", "JS.T", "JU.BSUtil", "JV.FileManager"], function(){
var c$ = Clazz.decorateAsClass(function(){
this.ms = null;
this.mat4 = null;
this.modelIndex = 0;
this.fileIndex = 0;
this.isBioModel = false;
this.isPdbWithMultipleBonds = false;
this.isModelKit = false;
this.chains = null;
this.simpleCage = null;
this.annotationCache = null;
this.orientation = null;
this.auxiliaryInfo = null;
this.properties = null;
this.biosymmetry = null;
this.translation = null;
this.loadState = "";
this.loadScript = null;
this.hasRasmolHBonds = false;
this.structureTainted = false;
this.isJmolDataFrame = false;
this.isTrajectory = false;
this.trajectoryBaseIndex = 0;
this.altLocCount = 0;
this.insertionCount = 0;
this.act = 0;
this.bondCount = -1;
this.chainCount = 0;
this.groupCount = -1;
this.hydrogenCount = 0;
this.moleculeCount = 0;
this.biosymmetryCount = 0;
this.firstAtomIndex = 0;
this.firstMoleculeIndex = 0;
this.bsAtoms = null;
this.bsAtomsDeleted = null;
this.defaultRotationRadius = 0;
this.frameDelay = 0;
this.selectedTrajectory = -1;
this.jmolData = null;
this.jmolFrameType = null;
this.jmolFrameTypeInt = 0;
this.dataFrames = null;
this.dataSourceFrame = 0;
this.uvw = null;
this.uvw0 = null;
this.pdbID = null;
this.bsCheck = null;
this.hasChirality = false;
this.isOrderly = true;
this.bsAsymmetricUnit = null;
Clazz.instantialize(this, arguments);}, JM, "Model", null);
Clazz.makeConstructor(c$, 
function(){
this.setupArrays();
});
Clazz.defineMethod(c$, "setupArrays", 
function(){
this.chains =  new Array(8);
this.loadScript =  new JU.SB();
this.bsAtoms =  new JU.BS();
this.bsAtomsDeleted =  new JU.BS();
});
Clazz.defineMethod(c$, "set", 
function(modelSet, modelIndex, trajectoryBaseIndex, jmolData, properties, auxiliaryInfo){
this.ms = modelSet;
this.dataSourceFrame = this.modelIndex = modelIndex;
this.isTrajectory = (trajectoryBaseIndex >= 0);
this.trajectoryBaseIndex = (this.isTrajectory ? trajectoryBaseIndex : modelIndex);
if (auxiliaryInfo == null) {
auxiliaryInfo =  new java.util.Hashtable();
}this.auxiliaryInfo = auxiliaryInfo;
var bc = (auxiliaryInfo.get("biosymmetryCount"));
if (bc != null) {
this.biosymmetryCount = bc.intValue();
this.biosymmetry = auxiliaryInfo.get("bioSymmetry");
}var fname = auxiliaryInfo.get("fileName");
if (fname != null) auxiliaryInfo.put("fileName", JV.FileManager.stripTypePrefix(fname));
this.properties = properties;
if (jmolData == null) {
this.jmolFrameType = "modelSet";
} else {
var jmolDataHeader = jmolData.get("header");
this.jmolData = jmolData;
this.isJmolDataFrame = true;
auxiliaryInfo.put("jmolData", jmolData);
auxiliaryInfo.put("title", jmolDataHeader);
this.jmolFrameType = (jmolDataHeader.indexOf("ramachandran") >= 0 ? "ramachandran" : jmolDataHeader.indexOf("quaternion") >= 0 ? "quaternion" : jmolDataHeader.indexOf("spin") >= 0 ? "spin" : "data");
this.jmolFrameTypeInt = JS.T.getTokFromName(this.jmolFrameType);
System.out.println("JmolFrameType is " + JS.T.nameOf(this.jmolFrameTypeInt));
}return this;
}, "JM.ModelSet,~N,~N,java.util.Map,java.util.Properties,java.util.Map");
Clazz.defineMethod(c$, "getTrueAtomCount", 
function(){
return JU.BSUtil.andNot(this.bsAtoms, this.bsAtomsDeleted).cardinality();
});
Clazz.defineMethod(c$, "isContainedIn", 
function(bs){
if (this.bsCheck == null) this.bsCheck =  new JU.BS();
this.bsCheck.clearAll();
this.bsCheck.or(bs);
var bsa = JU.BSUtil.andNot(this.bsAtoms, this.bsAtomsDeleted);
this.bsCheck.and(bsa);
return this.bsCheck.equals(bsa);
}, "JU.BS");
Clazz.defineMethod(c$, "resetBoundCount", 
function(){
this.bondCount = -1;
});
Clazz.defineMethod(c$, "getBondCount", 
function(){
if (this.bondCount >= 0) return this.bondCount;
var bonds = this.ms.bo;
this.bondCount = 0;
for (var i = this.ms.bondCount; --i >= 0; ) if (bonds[i] != null && bonds[i].atom1.mi == this.modelIndex) this.bondCount++;

return this.bondCount;
});
Clazz.defineMethod(c$, "getChainCount", 
function(countWater){
if (this.chainCount > 1 && !countWater) for (var i = 0; i < this.chainCount; i++) if (this.chains[i].chainID == 0) return this.chainCount - 1;

return this.chainCount;
}, "~B");
Clazz.defineMethod(c$, "calcSelectedGroupsCount", 
function(bsSelected){
for (var i = this.chainCount; --i >= 0; ) this.chains[i].calcSelectedGroupsCount(bsSelected);

}, "JU.BS");
Clazz.defineMethod(c$, "getGroupCount", 
function(){
if (this.groupCount < 0) {
this.groupCount = 0;
for (var i = this.chainCount; --i >= 0; ) this.groupCount += this.chains[i].groupCount;

}return this.groupCount;
});
Clazz.defineMethod(c$, "getChainAt", 
function(i){
return (i < this.chainCount ? this.chains[i] : null);
}, "~N");
Clazz.defineMethod(c$, "getChain", 
function(chainID){
for (var i = this.chainCount; --i >= 0; ) {
var chain = this.chains[i];
if (chain.chainID == chainID) return chain;
}
return null;
}, "~N");
Clazz.defineMethod(c$, "resetDSSR", 
function(totally){
this.annotationCache = null;
if (totally) this.auxiliaryInfo.remove("dssr");
}, "~B");
Clazz.defineMethod(c$, "fixIndices", 
function(modelIndex, nAtomsDeleted, bsDeleted){
this.fixIndicesM(modelIndex, nAtomsDeleted, bsDeleted);
}, "~N,~N,JU.BS");
Clazz.defineMethod(c$, "fixIndicesM", 
function(modelIndex, nAtomsDeleted, bsDeleted){
if (this.dataSourceFrame > modelIndex) this.dataSourceFrame--;
if (this.trajectoryBaseIndex > modelIndex) this.trajectoryBaseIndex--;
this.firstAtomIndex -= nAtomsDeleted;
for (var i = 0; i < this.chainCount; i++) this.chains[i].fixIndices(nAtomsDeleted, bsDeleted);

JU.BSUtil.deleteBits(this.bsAtoms, bsDeleted);
JU.BSUtil.deleteBits(this.bsAtomsDeleted, bsDeleted);
}, "~N,~N,JU.BS");
Clazz.defineMethod(c$, "freeze", 
function(){
this.freezeM();
return false;
});
Clazz.defineMethod(c$, "freezeM", 
function(){
for (var i = 0; i < this.chainCount; i++) if (this.chains[i].groupCount == 0) {
for (var j = i + 1; j < this.chainCount; j++) this.chains[j - 1] = this.chains[j];

this.chainCount--;
}
this.chains = JU.AU.arrayCopyObject(this.chains, this.chainCount);
this.groupCount = -1;
this.getGroupCount();
for (var i = 0; i < this.chainCount; ++i) this.chains[i].groups = JU.AU.arrayCopyObject(this.chains[i].groups, this.chains[i].groupCount);

});
Clazz.defineMethod(c$, "getUVWMatrix", 
function(isUVW0){
if (this.uvw0 == null) {
return null;
}var m =  new JU.M3();
for (var i = 0; i < 3; i++) {
var p = JU.P3.newP(isUVW0 ? this.uvw0[i] : this.uvw[i]);
p.normalize();
m.setColumnV(i, p);
}
return m;
}, "~B");
});
;//5.0.1-v7 Mon Aug 24 09:54:36 CDT 2026
