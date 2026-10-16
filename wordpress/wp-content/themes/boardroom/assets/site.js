(function(){var h=document.querySelector('.hero');if(h){var s=h.querySelectorAll('.slide'),d=h.querySelector('.dots'),i=0,t;
s.forEach(function(_,n){var b=document.createElement('button');b.setAttribute('aria-label','Slide '+(n+1));b.onclick=function(){go(n)};d.appendChild(b)});
function go(n){s[i].classList.remove('on');d.children[i].classList.remove('on');i=(n+s.length)%s.length;s[i].classList.add('on');d.children[i].classList.add('on');clearInterval(t);t=setInterval(function(){go(i+1)},5000)}
h.querySelector('.prev').onclick=function(){go(i-1)};h.querySelector('.next').onclick=function(){go(i+1)};go(0);}
var lb=document.querySelector('.lightbox');document.querySelectorAll('.gallery a').forEach(function(a){a.onclick=function(e){e.preventDefault();lb.querySelector('img').src=a.href;lb.classList.add('on')}});})();
