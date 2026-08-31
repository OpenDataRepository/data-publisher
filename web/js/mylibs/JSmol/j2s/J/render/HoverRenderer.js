Clazz.declarePackage("J.render");
Clazz.load(["J.render.ShapeRenderer"], "J.render.HoverRenderer", ["JU.P3", "J.render.TextRenderer"], function(){
var c$ = Clazz.decorateAsClass(function(){
this.tempXY = null;
this.ptTemp = null;
Clazz.instantialize(this, arguments);}, J.render, "HoverRenderer", J.render.ShapeRenderer);
Clazz.prepareFields (c$, function(){
this.tempXY =  Clazz.newFloatArray (3, 0);
});
Clazz.overrideMethod(c$, "render", 
function(){
if (!this.vwr.showHover()) return false;
if (this.ptTemp == null) this.ptTemp =  new JU.P3();
var hover = this.shape;
var antialias = this.g3d.isAntialiased();
var text = hover.hoverText;
var label;
var withPointer = (hover.withPointer === Boolean.TRUE);
var pointerWidth = 15;
var pointerMode = (withPointer ? 1 : 0);
if (hover.atomIndex >= 0) {
var atom = this.ms.at[hover.atomIndex];
label = (hover.specialLabel != null ? hover.specialLabel : hover.atomFormats != null && hover.atomFormats[hover.atomIndex] != null ? this.ms.getLabeler().formatLabel(this.vwr, atom, hover.atomFormats[hover.atomIndex], this.ptTemp) : hover.labelFormat != null ? this.ms.getLabeler().formatLabel(this.vwr, atom, this.fixLabel(atom, hover.labelFormat), this.ptTemp) : null);
if (label == null) return false;
if (pointerMode == 1) {
text.atomX = atom.sX;
text.atomY = atom.sY;
text.atomZ = text.z;
}text.setXYZs(atom.sX, atom.sY, 1, -2147483648);
} else if (hover.text != null) {
label = hover.text;
text.setXYZs(hover.xy.x, hover.xy.y, 1, -2147483648);
} else {
return true;
}if (this.vwr != null) label = this.vwr.formatText(label);
text.setText(label);
var pointerColix = text.bgcolix;
J.render.TextRenderer.render(null, text, this.g3d, 0, antialias ? 2 : 1, null, this.tempXY, null, pointerColix, pointerWidth, pointerMode);
return true;
});
Clazz.defineMethod(c$, "fixLabel", 
function(atom, label){
if (label == null || atom == null) return null;
return (this.ms.isJmolDataFrame(atom.mi) && label.equals("%U") ? "%W" : label);
}, "JM.Atom,~S");
});
;//5.0.1-v7 Mon Aug 24 10:01:38 CDT 2026
