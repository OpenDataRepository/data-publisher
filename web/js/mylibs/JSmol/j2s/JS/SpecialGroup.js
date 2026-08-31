Clazz.declarePackage("JS");
Clazz.load(["JS.SpaceGroup"], "JS.SpecialGroup", ["JU.PT", "JU.SimpleUnitCell"], function(){
var c$ = Clazz.decorateAsClass(function(){
this.embeddingSymmetry = null;
Clazz.instantialize(this, arguments);}, JS, "SpecialGroup", JS.SpaceGroup);
Clazz.makeConstructor(c$, 
function(sym, info, type){
Clazz.superConstructor(this, JS.SpecialGroup, [-1, null, true]);
this.embeddingSymmetry = sym;
this.groupType = type;
if (info == null) return;
this.initSpecial(info);
}, "JS.Symmetry,java.util.Map,~N");
Clazz.defineMethod(c$, "initSpecial", 
function(info){
var ops = info.get("gp");
for (var i = 0; i < ops.size(); i++) {
this.addOperation(ops.get(i), 0, false);
}
this.setTransform(info.get("trm"));
this.itaNumber = "" + info.get("sg");
this.itaIndex = "" + info.get("set");
this.specialPrefix = JS.SpaceGroup.getGroupTypePrefix(this.groupType);
this.setHMSymbol(info.get("hm"));
this.setITATableNames(null, this.itaNumber, this.itaIndex, this.itaTransform);
}, "java.util.Map");
Clazz.defineMethod(c$, "setTransform", 
function(transform){
this.itaTransform = transform;
}, "~S");
Clazz.defineMethod(c$, "checkCompatible", 
function(params, newParams, allowSame, monoclinic_oblique, monoclinic_orthogonal, orthorhombic, tetragonal){
var n = (this.itaNumber == null ? 0 : JU.PT.parseInt(this.itaNumber));
var toHex = (n != 0 && this.isHexagonalSG(n, null));
var isHex = (toHex && this.isHexagonalSG(-1, params));
if (toHex && isHex) {
allowSame = true;
}var pc =  new JS.SpaceGroup.ParamCheck(params, allowSame, true);
if (n > (allowSame ? 2 : 0)) {
if (toHex) {
pc.b = pc.a;
pc.alpha = pc.beta = 90;
pc.gamma = 120;
} else if (n >= tetragonal) {
pc.b = pc.a;
if (pc.acsame && !allowSame) pc.c = pc.a * 1.5;
pc.alpha = pc.beta = pc.gamma = 90;
} else if (n >= orthorhombic) {
pc.alpha = pc.beta = pc.gamma = 90;
} else if (n >= monoclinic_orthogonal) {
pc.beta = 90;
if (this.groupType == 400) {
pc.gamma = 90;
} else {
pc.alpha = 90;
}} else if (n >= monoclinic_oblique) {
pc.beta = 90;
if (this.groupType == 400) {
pc.alpha = 90;
} else {
pc.gamma = 90;
}}}return pc.checkNew(params, newParams == null ? params : newParams);
}, "~A,~A,~B,~N,~N,~N,~N");
/*if3*/;(function(){
var c$ = Clazz.declareType(JS.SpecialGroup, "PlaneGroup", JS.SpecialGroup);
Clazz.makeConstructor(c$, 
function(sym, info){
Clazz.superConstructor(this, JS.SpecialGroup.PlaneGroup, [sym, info, 300]);
this.nDim = 2;
this.periodicity = 3;
}, "JS.Symmetry,java.util.Map");
Clazz.overrideMethod(c$, "createCompatibleUnitCell", 
function(params, newParams, allowSame){
var n = (this.itaNumber == null ? 0 : JU.PT.parseInt(this.itaNumber));
var toHex = false;
var isHex = false;
toHex = (n != 0 && this.isHexagonalSG(n, null));
isHex = (toHex && this.isHexagonalSG(-1, params));
if (toHex && isHex) {
allowSame = true;
}var pc =  new JS.SpaceGroup.ParamCheck(params, allowSame, false);
pc.c = 0.5;
pc.alpha = pc.beta = 90;
if (n > (allowSame ? 2 : 0)) {
if (toHex) {
pc.b = pc.a;
pc.gamma = 120;
} else if (n >= 10) {
pc.b = pc.a;
pc.gamma = 90;
} else if (n >= 3) {
pc.gamma = 90;
}}return pc.checkNew(params, newParams == null ? params : newParams);
}, "~A,~A,~B");
Clazz.overrideMethod(c$, "isHexagonalSG", 
function(n, params){
return (n < 1 ? JU.SimpleUnitCell.isHexagonal(params) : n >= 13);
}, "~N,~A");
/*eoif3*/})();
/*if3*/;(function(){
var c$ = Clazz.declareType(JS.SpecialGroup, "LayerGroup", JS.SpecialGroup);
Clazz.makeConstructor(c$, 
function(sym, info){
Clazz.superConstructor(this, JS.SpecialGroup.LayerGroup, [sym, info, 400]);
this.nDim = 3;
this.periodicity = 0x3;
}, "JS.Symmetry,java.util.Map");
Clazz.overrideMethod(c$, "createCompatibleUnitCell", 
function(params, newParams, allowSame){
return this.checkCompatible(params, newParams, allowSame, 3, 8, 19, 49);
}, "~A,~A,~B");
Clazz.overrideMethod(c$, "isHexagonalSG", 
function(n, params){
return (n < 1 ? JU.SimpleUnitCell.isHexagonal(params) : n >= 65);
}, "~N,~A");
/*eoif3*/})();
/*if3*/;(function(){
var c$ = Clazz.declareType(JS.SpecialGroup, "RodGroup", JS.SpecialGroup);
Clazz.makeConstructor(c$, 
function(sym, info){
Clazz.superConstructor(this, JS.SpecialGroup.RodGroup, [sym, info, 500]);
this.nDim = 3;
if (info != null) this.periodicity = this.setRodPeriodicityFromTrm(info);
}, "JS.Symmetry,java.util.Map");
Clazz.defineMethod(c$, "setRodPeriodicityFromTrm", 
function(info){
var sg = (info.get("sg")).intValue();
if (sg < 3 || sg > 22) {
return 0x4;
}var trm = info.get("trm");
if (trm.endsWith("c")) {
return 0x4;
}return (trm.indexOf('c') < trm.indexOf(',') ? 0x1 : 0x2);
}, "java.util.Map");
Clazz.overrideMethod(c$, "createCompatibleUnitCell", 
function(params, newParams, allowSame){
return this.checkCompatible(params, newParams, allowSame, 3, 8, 13, 23);
}, "~A,~A,~B");
Clazz.overrideMethod(c$, "isHexagonalSG", 
function(n, params){
return (n < 1 ? JU.SimpleUnitCell.isHexagonal(params) : n >= 42);
}, "~N,~A");
/*eoif3*/})();
/*if3*/;(function(){
var c$ = Clazz.declareType(JS.SpecialGroup, "FriezeGroup", JS.SpecialGroup);
Clazz.makeConstructor(c$, 
function(sym, info){
Clazz.superConstructor(this, JS.SpecialGroup.FriezeGroup, [sym, info, 600]);
this.nDim = 2;
this.periodicity = 0x1;
}, "JS.Symmetry,java.util.Map");
Clazz.overrideMethod(c$, "createCompatibleUnitCell", 
function(params, newParams, allowSame){
var a = params[0];
var b = params[0];
var c = -1;
var alpha = 90;
var beta = 90;
var gamma = 90;
var isNew = !(a == params[0] && b == params[1] && c == params[2] && alpha == params[3] && beta == params[4] && gamma == params[5]);
if (newParams == null) newParams = params;
newParams[0] = a;
newParams[1] = b;
newParams[2] = c;
newParams[3] = alpha;
newParams[4] = beta;
newParams[5] = gamma;
return isNew;
}, "~A,~A,~B");
/*eoif3*/})();
});
;//5.0.1-v7 Mon Aug 17 10:07:10 MDT 2026
