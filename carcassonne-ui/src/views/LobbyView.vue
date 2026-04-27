<template>
  <div class="page-container">
    <div class="content">
      <h1>Lobby Jocuri</h1>
      <p>Alege un joc existent sau creează unul nou.</p>
      
      <div class="lobby-actions">
        <button class="btn-primary" @click="$router.push('/game/123')">
          Alătură-te Jocului #123
        </button>
        <br><br>
        <button class="btn-primary">Creează Joc Nou</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.page-container {
  background-color: #c8e6c9;
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  align-items: center;
  padding-top: 50px;
  text-align: center;
}

.btn-primary {
  background-color: #2e7d32;
  color: white;
  border: none;
  padding: 12px 24px;
  border-radius: 25px;
  font-size: 1rem;
  cursor: pointer;
}
</style>