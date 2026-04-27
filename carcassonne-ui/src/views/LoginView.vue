<template>
  <div class="page-container">
    <div class="content">
      <h1>Carcassonne Light</h1>
      <p>Autentifică-te pentru a juca.</p>
      
      <div class="form-placeholder">
        <button class="btn-primary" @click="$router.push('/lobby')">
          Intră în cont
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* Stilurile specifice sunt acum mostenite din App.vue sau definite global */
.page-container {
  background-color: #c8e6c9; /* Verde deschis */
  min-height: 100vh;
  display: flex;
  justify-content: center;
  align-items: center;
  text-align: center;
}

.btn-primary {
  background-color: #2e7d32; /* Verde mai inchis */
  color: white;
  border: none;
  padding: 12px 24px;
  border-radius: 25px; /* Rotunjit */
  font-size: 1rem;
  cursor: pointer;
  transition: background 0.3s;
}

.btn-primary:hover {
  background-color: #1b5e20;
}
</style>