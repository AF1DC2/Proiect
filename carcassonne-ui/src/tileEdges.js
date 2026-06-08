export const TILE_EDGES = {
  tile_01: ['C', 'C', 'C', 'C'], 
  tile_02: ['C', 'C', 'R', 'C'], 
  tile_03: ['C', 'C', 'C', 'R'], 
  tile_04: ['R', 'C', 'C', 'C'], 
  tile_05: ['C', 'R', 'C', 'C'], 
  tile_06: ['C', 'C', 'F', 'C'],  
  tile_07: ['C', 'C', 'C', 'F'],  
  tile_08: ['F', 'C', 'C', 'C'], 
  tile_09: ['C', 'F', 'C', 'C'],  

  tile_10: ['R', 'R', 'R', 'F'], 
  tile_11: ['F', 'F', 'R', 'R'],  
  tile_12: ['R', 'F', 'F', 'R'],   
  tile_13: ['R', 'R', 'F', 'F'], 
  tile_24: ['F', 'F', 'R', 'F'], 

  tile_14: ['C', 'C', 'F', 'F'], 
  tile_15: ['F', 'C', 'C', 'F'], 
  tile_16: ['F', 'F', 'C', 'C'],  
  tile_17: ['C', 'F', 'F', 'C'], 
  tile_18: ['C', 'C', 'R', 'R'], 
  tile_19: ['R', 'C', 'C', 'R'], 
  tile_20: ['R', 'R', 'C', 'C'], 
  tile_21: ['C', 'R', 'R', 'C'], 
  tile_22: ['F', 'F', 'R', 'F'], 
  tile_23: ['F', 'R', 'R', 'F'], 

  tile_25: ['F', 'R', 'F', 'F'], 
  tile_26: ['C', 'F', 'C', 'F'], 
  tile_27: ['F', 'C', 'F', 'C'], 
  tile_28: ['C', 'F', 'F', 'C'], 
  tile_29: ['C', 'C', 'F', 'F'], 
  tile_30: ['F', 'F', 'F', 'R'], 
  tile_31: ['F', 'F', 'C', 'C'], 
  tile_32: ['R', 'R', 'R', 'R'], 
  tile_33: ['R', 'R', 'F', 'R'], 
  tile_34: ['R', 'C', 'C', 'R'],  
  tile_35: ['R', 'R', 'C', 'C'], 
  tile_36: ['C', 'C', 'C', 'C'], 
  tile_37: ['C', 'R', 'C', 'R'], 
  tile_38: ['R', 'C', 'R', 'C'], 
  tile_39: ['F', 'C', 'F', 'C'], 
  tile_40: ['C', 'F', 'C', 'F'],  
  tile_41: ['F', 'R', 'R', 'R'], 
  tile_42: ['C', 'F', 'C', 'F'], 
  tile_43: ['R', 'C', 'R', 'C'], 
  tile_44: ['C', 'R', 'C', 'R'], 
  tile_45: ['F', 'C', 'R', 'C'], 
  tile_46: ['C', 'F', 'C', 'R'], 
  tile_47: ['R', 'F', 'R', 'R'], 
  tile_48: ['C', 'R', 'C', 'F'],  

  tile_49: ['C', 'F', 'F', 'F'], 
  tile_50: ['F', 'C', 'F', 'F'], 
  tile_51: ['F', 'F', 'C', 'F'], 
  tile_52: ['F', 'F', 'F', 'C'], 
  tile_53: ['C', 'F', 'R', 'R'], 
  tile_54: ['R', 'C', 'F', 'R'], 
  tile_55: ['R', 'R', 'C', 'F'], 
  tile_56: ['F', 'R', 'R', 'C'],  
  tile_57: ['C', 'R', 'R', 'F'],  
  tile_58: ['F', 'C', 'R', 'R'], 
  tile_59: ['R', 'F', 'C', 'R'], 
  tile_60: ['R', 'R', 'F', 'C'], 
  tile_61: ['C', 'F', 'R', 'F'], 
  tile_62: ['F', 'C', 'F', 'R'], 
  tile_63: ['R', 'F', 'C', 'F'],  
  tile_64: ['F', 'R', 'F', 'C'],  
  tile_65: ['C', 'F', 'F', 'R'],  
  tile_66: ['R', 'C', 'F', 'F'],  
  tile_67: ['F', 'R', 'C', 'F'],  
  tile_68: ['F', 'F', 'R', 'C'], 
  tile_69: ['C', 'R', 'F', 'F'],  
  tile_70: ['F', 'C', 'R', 'F'],  
  tile_71: ['F', 'F', 'C', 'R'],  
  tile_72: ['R', 'F', 'F', 'C'],  
};

export function getRotatedEdges(tileId, rotation) {
  const base = TILE_EDGES[tileId];
  if (!base) return null; 
  const steps = ((rotation % 360) / 90) | 0;
  const edges = [...base];
  for (let i = 0; i < steps; i++) {
    edges.unshift(edges.pop());
  }
  return edges;
}

export function checkEdgeMatch(board, x, y, tileId, rotation) {
  const myEdges = getRotatedEdges(tileId, rotation);
  if (!myEdges) return { ok: true }; 

  const neighbors = [
    { key: `${x},${y + 1}`, mine: 0, theirs: 2 }, 
    { key: `${x + 1},${y}`, mine: 1, theirs: 3 }, 
    { key: `${x},${y - 1}`, mine: 2, theirs: 0 }, 
    { key: `${x - 1},${y}`, mine: 3, theirs: 1 }, 
  ];

  for (const n of neighbors) {
    const neighbor = board[n.key];
    if (!neighbor) continue; 

    const theirEdges = getRotatedEdges(neighbor.tileId, neighbor.rotation);
    if (!theirEdges) continue; 

    if (myEdges[n.mine] !== theirEdges[n.theirs]) {
      const dir = ['sus', 'dreapta', 'jos', 'stanga'][n.mine];
      return {
        ok: false,
        reason: `Marginile nu se potrivesc spre ${dir} (${myEdges[n.mine]} vs ${theirEdges[n.theirs]}). Roteste piesa sau alege alta pozitie.`,
      };
    }
  }

  return { ok: true };
}
