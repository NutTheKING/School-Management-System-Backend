const slides=[...document.querySelectorAll('.slide')];
const dots=[...document.querySelectorAll('.dots button')];
let current=0;
function showSlide(index){current=index;slides.forEach((s,i)=>s.classList.toggle('active',i===index));dots.forEach((d,i)=>d.classList.toggle('active',i===index))}
dots.forEach((dot,index)=>dot.addEventListener('click',()=>showSlide(index)));
setInterval(()=>showSlide((current+1)%slides.length),5000);
const toast=document.querySelector('.toast');let toastTimer;
document.querySelectorAll('.feature').forEach(button=>button.addEventListener('click',()=>{toast.textContent=`Opening ${button.dataset.label}…`;toast.classList.add('show');clearTimeout(toastTimer);toastTimer=setTimeout(()=>toast.classList.remove('show'),1600)}));
document.querySelectorAll('.bottom-nav button:not(.scan)').forEach(button=>button.addEventListener('click',()=>{document.querySelectorAll('.bottom-nav button').forEach(b=>b.classList.remove('selected'));button.classList.add('selected')}));
document.querySelector('.scan').addEventListener('click',()=>{toast.textContent='Student scanner ready';toast.classList.add('show');clearTimeout(toastTimer);toastTimer=setTimeout(()=>toast.classList.remove('show'),1600)});
