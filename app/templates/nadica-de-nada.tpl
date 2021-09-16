{extends 'layout.tpl'}

{block name=content}
<strong>{$name}</strong>
<canvas id="myChart" width="400" height="200"></canvas>
{/block}

{block name=javascript}
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.5.1/dist/chart.min.js" integrity="sha256-bC3LCZCwKeehY6T4fFi9VfOU0gztUa+S4cnkIhVPZ5E=" crossorigin="anonymous"></script>
<script>
var ctx = document.getElementById('myChart').getContext('2d');
var myChart = new Chart(ctx, {
    type: 'bar',
    data: {
        labels: {json_encode($labels)},
        datasets: {json_encode($datasets)}
    },
    options: {
        scales: {
            y: {
                beginAtZero: true
            }
        }
    }
});
</script>

{/block}
