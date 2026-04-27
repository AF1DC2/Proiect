<template>
  <div class="page-container game-view">
    <header class="game-header">
      <h1>Joc în Desfășurare</h1>
      <p>ID Sesiune: {{ $route.params.id }} | Jucător: {{ playerInfo.playerName }}</p>
      <button class="btn-primary" @click="$router.push('/lobby')">Părăsește Jocul</button>
    </header>

    <main class="game-layout">
      <section class="hand-section">
        <h3>Piesa Curentă</h3>
        <div 
          class="tile-preview"
          :style="{ transform: `rotate(${currentTile.rotation}deg)` }"
          @click="rotateTile"
          draggable="true"
          @dragstart="onDragStart"
        >
          <div class="tile-content">
            <span>{{ currentTile.tileId }}</span>
            <div class="rotation-indicator">↻</div>
          </div>
        </div>
      </section>

      <section class="board-section">
        <div class="grid">
          <div v-for="y in gridRange.y" :key="'row-'+y" class="grid-row">
            <div 
              v-for="x in gridRange.x" 
              :key="`cell-${x}-${y}`"
              class="grid-cell"
              @dragover.prevent
              @drop="onDrop($event, x, y)"
            >
              <div 
                v-if="board[`${x},${y}`]" 
                class="placed-tile"
                :style="{ transform: `rotate(${board[`${x},${y}`].rotation}deg)` }"
              >
                {{ board[`${x},${y}`].tileId }}
              </div>
            </div>
          </div>
        </div>
      </section>
    </main>
  </div>
</template>

<script setup>
import { ref, reactive, computed } from 'vue';

const playerInfo = reactive({ playerName: 'MeepleMaster', score: 0 });
const currentTile = ref({ tileId: 'tile_01', rotation: 0 });
const board = reactive({ '0,0': { tileId: 'START', rotation: 0 } });

const gridRange = computed(() => {
  const coords = Object.keys(board).map(k => k.split(',').map(Number));
  const xs = coords.map(c => c[0]);
  const ys = coords.map(c => c[1]);
  return {
    x: Array.from({ length: (Math.max(...xs) + 1) - (Math.min(...xs) - 1) + 1 }, (_, i) => Math.min(...xs) - 1 + i),
    y: Array.from({ length: (Math.max(...ys) + 1) - (Math.min(...ys) - 1) + 1 }, (_, i) => Math.max(...ys) + 1 - i)
  };
});

const rotateTile = () => {
  currentTile.value.rotation = (currentTile.value.rotation + 90) % 360;
};

const onDragStart = (event) => {
  event.dataTransfer.setData('tileData', JSON.stringify(currentTile.value));
};

const onDrop = (event, x, y) => {
  if (board[`${x},${y}`]) return;
  const tileData = JSON.parse(event.dataTransfer.getData('tileData'));
  board[`${x},${y}`] = { ...tileData };
  currentTile.value = { tileId: 'tile_' + Math.floor(Math.random() * 20), rotation: 0 };
};
</script>

<style scoped>
.page-container {
  background-color: #c8e6c9;
  min-height: 100vh;
  text-align: center;
  padding: 20px;
}

.btn-primary {
  background-color: #2e7d32;
  color: white;
  border: none;
  padding: 10px 20px;
  border-radius: 25px;
  cursor: pointer;
}

.game-layout {
  display: flex;
  justify-content: center;
  gap: 40px;
  margin-top: 30px;
}

.tile-preview {
  width: 100px;
  height: 100px;
  border: 3px solid #2e7d32;
  margin: 0 auto;
  cursor: pointer;
  background: white;
  display: flex;
  align-items: center;
  justify-content: center;
}

.grid-row { display: flex; justify-content: center; }

.grid-cell {
  width: 60px;
  height: 60px;
  border: 1px solid #a5d6a7;
  display: flex;
  align-items: center;
  justify-content: center;
  background-color: rgba(255, 255, 255, 0.3);
}

.placed-tile {
  width: 100%;
  height: 100%;
  background: #4caf50;
  color: white;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: bold;
}
</style>