Clazz.declarePackage("JM");
Clazz.load(["JM.Structure"], "JM.Chain", ["JU.AU"], function(){
var c$ = Clazz.decorateAsClass(function(){
this.model = null;
this.chainID = 0;
this.chainNo = 0;
this.groups = null;
this.groupCount = 0;
this.selectedGroupCount = 0;
Clazz.instantialize(this, arguments);}, JM, "Chain", null, JM.Structure);
Clazz.makeConstructor(c$, 
function(model, chainID, chainNo){
this.model = model;
this.chainID = chainID;
this.chainNo = chainNo;
this.groups =  new Array(16);
}, "JM.Model,~N,~N");
Clazz.defineMethod(c$, "getIDStr", 
function(){
return (this.chainID == 0 ? "" : this.chainID < 256 ? "" + String.fromCharCode(this.chainID) : this.model.ms.vwr.getChainIDStr(this.chainID));
});
Clazz.defineMethod(c$, "calcSelectedGroupsCount", 
function(bsSelected){
this.selectedGroupCount = 0;
for (var i = 0; i < this.groupCount; i++) this.groups[i].selectedIndex = (this.groups[i].isSelected(bsSelected) ? this.selectedGroupCount++ : -1);

}, "JU.BS");
Clazz.defineMethod(c$, "fixIndices", 
function(atomsDeleted, bsDeleted){
for (var i = 0; i < this.groupCount; i++) this.groups[i].fixIndices(atomsDeleted, bsDeleted);

}, "~N,JU.BS");
Clazz.overrideMethod(c$, "setAtomBits", 
function(bs){
for (var i = 0; i < this.groupCount; i++) this.groups[i].setAtomBits(bs);

}, "JU.BS");
Clazz.overrideMethod(c$, "setAtomBitsAndClear", 
function(bs, bsOut){
for (var i = 0; i < this.groupCount; i++) this.groups[i].setAtomBitsAndClear(bs, bsOut);

}, "JU.BS,JU.BS");
Clazz.defineMethod(c$, "addGroup", 
function(group, groupIndex){
if (this.groupCount == this.groups.length) this.groups = JU.AU.doubleLength(this.groups);
this.groups[this.groupCount++] = group;
group.groupIndex = groupIndex;
return group;
}, "JM.Group,~N");
});
;//5.0.1-v7 Mon Aug 24 10:01:38 CDT 2026
